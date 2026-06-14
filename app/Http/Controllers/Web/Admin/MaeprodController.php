<?php

namespace App\Http\Controllers\Web\Admin;

use App\Http\Controllers\Controller;
use App\Models\Maeprod;
use App\Models\MaeprodImportRun;
use App\Services\MaeprodAdminService;
use App\Services\MaeprodChunkUploadService;
use App\Services\MaeprodImportJobService;
use App\Services\MaeprodImportLockService;
use App\Services\MaeprodImportProgressService;
use App\Services\MaeprodImportRunService;
use App\Services\MaeprodImportService;
use App\Services\MaeprodImportStagingService;
use App\Support\MaeprodImportColumnMapping;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

class MaeprodController extends Controller
{
    public function __construct(
        protected MaeprodAdminService $maeprodService,
        protected MaeprodImportService $importService,
    ) {}

    public function index(Request $request): View
    {
        $productos = $this->maeprodService->listado(
            $request->string('q')->trim()->toString() ?: null,
            $request->string('familia')->trim()->toString() ?: null,
            (int) config('cotiz.listado_por_pagina', 20),
        );

        return view('admin.maeprod.index', [
            'productos' => $productos,
            'familias' => $this->maeprodService->familias(),
            'filtros' => [
                'q' => $request->string('q')->toString(),
                'familia' => $request->string('familia')->toString(),
            ],
        ]);
    }

    public function create(): View
    {
        return view('admin.maeprod.form', [
            'producto' => null,
            'familias' => $this->maeprodService->familias(),
            'storageImagenConfigurado' => $this->maeprodService->almacenamientoImagenConfigurado(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $datos = $request->validate($this->maeprodService->reglasValidacion(true));
        $datos = $this->maeprodService->normalizarDatosConImagen($datos, $request->file('imagen'));

        $this->maeprodService->crear($datos, $request->user()->username);

        return redirect()
            ->route('admin.productos.index')
            ->with('success', 'Producto creado.');
    }

    public function edit(string $prod_item): View
    {
        $producto = Maeprod::query()->findOrFail($prod_item);

        return view('admin.maeprod.form', [
            'producto' => $producto,
            'familias' => $this->maeprodService->familias(),
            'storageImagenConfigurado' => $this->maeprodService->almacenamientoImagenConfigurado(),
        ]);
    }

    public function update(Request $request, string $prod_item): RedirectResponse
    {
        $producto = Maeprod::query()->findOrFail($prod_item);

        $datos = $request->validate($this->maeprodService->reglasValidacion(false));
        $datos = $this->maeprodService->normalizarDatosConImagen($datos, $request->file('imagen'), $producto);

        $this->maeprodService->actualizar($producto, $datos, $request->user()->username);

        return redirect()
            ->route('admin.productos.edit', $producto->prod_item)
            ->with('success', 'Producto actualizado.');
    }

    public function importForm(MaeprodImportLockService $importLock): View
    {
        return view('admin.maeprod.import', [
            'activeImport' => $importLock->currentOrReleaseIfAbandoned(),
            'mappableFields' => MaeprodImportColumnMapping::fieldDefinitions(),
        ]);
    }

    public function importStatus(MaeprodImportLockService $importLock): JsonResponse
    {
        $current = $importLock->currentOrReleaseIfAbandoned();

        return response()->json([
            'active' => $current !== null,
            'lock' => $current,
        ]);
    }

    public function importProgress(Request $request, MaeprodImportProgressService $progressService): JsonResponse
    {
        $data = $request->validate([
            'upload_id' => ['required', 'uuid'],
        ]);

        $progress = $progressService->read($data['upload_id']);

        if ($progress === null) {
            return response()->json(['message' => 'No hay progreso para esta importación.'], 404);
        }

        if ((int) ($progress['user_id'] ?? 0) !== (int) $request->user()->id) {
            return response()->json(['message' => 'No autorizado.'], 403);
        }

        return response()->json($progress);
    }

    public function startBackgroundImport(Request $request, MaeprodImportJobService $importJob): JsonResponse
    {
        $data = $request->validate([
            'upload_id' => ['required', 'uuid'],
            'mode' => ['nullable', 'in:template,custom'],
            'mapping' => ['nullable', 'array'],
        ]);

        $mode = (string) ($data['mode'] ?? 'template');
        $mapping = isset($data['mapping']) && is_array($data['mapping'])
            ? $this->normalizeColumnMapping($data['mapping'])
            : null;

        try {
            $importJob->queueBackgroundImport(
                $data['upload_id'],
                (int) $request->user()->id,
                $mode,
                $mapping,
            );
        } catch (\InvalidArgumentException $e) {
            return response()->json(
                ['message' => $e->getMessage()],
                $this->importConflictStatus($e),
            );
        }

        return response()->json([
            'queued' => true,
            'upload_id' => $data['upload_id'],
        ]);
    }

    public function releaseImportLock(Request $request, MaeprodImportLockService $importLock): JsonResponse
    {
        $data = $request->validate([
            'upload_id' => ['nullable', 'uuid'],
        ]);

        if (! empty($data['upload_id'])) {
            $importLock->release($data['upload_id']);
            app(MaeprodImportProgressService::class)->forget($data['upload_id']);
        } else {
            $importLock->forceRelease();
        }

        return response()->json(['released' => true]);
    }

    public function importResult(int $run, MaeprodImportRunService $runService): View
    {
        return view('admin.maeprod.import-resultado', [
            'run' => $runService->findRun($run),
        ]);
    }

    public function importErrors(Request $request, int $run, MaeprodImportRunService $runService): View
    {
        $runModel = $runService->findRun($run);

        return view('admin.maeprod.import-errores', [
            'run' => $runModel,
            'errores' => $runService->paginateErrors(
                $runModel,
                (int) config('cotiz.listado_por_pagina', 50),
            ),
        ]);
    }

    public function exportImportErrors(int $run, MaeprodImportRunService $runService): StreamedResponse
    {
        $runModel = $runService->findRun($run);
        abort_unless($runModel->tieneErrores(), 404);

        return $runService->exportErrorsCsvResponse($runModel);
    }

    public function downloadImportTemplate(): StreamedResponse
    {
        return $this->importService->templateCsvDownloadResponse();
    }

    public function downloadImportTemplateExcel(): StreamedResponse
    {
        return $this->importService->templateExcelDownloadResponse();
    }

    public function exportCsv(): StreamedResponse
    {
        return $this->importService->exportCsvResponse();
    }

    public function storeImportChunk(Request $request, MaeprodChunkUploadService $chunkUpload): JsonResponse
    {
        if (! $request->hasFile('chunk') || ! $request->file('chunk')->isValid()) {
            return response()->json([
                'message' => 'El fragmento no llegó al servidor. Reintenta la carga.',
            ], 422);
        }

        $data = $request->validate([
            'upload_id' => ['required', 'uuid'],
            'chunk_index' => ['required', 'integer', 'min:0'],
            'total_chunks' => ['required', 'integer', 'min:1', 'max:500'],
            'original_name' => ['required', 'string', 'max:255'],
            'mode' => ['nullable', 'in:template,custom'],
            'chunk' => ['required', 'file', 'max:7168'],
        ]);

        $mode = $data['mode'] ?? 'template';

        try {
            $result = $chunkUpload->storeChunk(
                $data['upload_id'],
                (int) $data['chunk_index'],
                (int) $data['total_chunks'],
                $data['original_name'],
                $request->file('chunk'),
                (int) $request->user()->id,
                $request->user()->username,
                $mode,
            );
        } catch (\InvalidArgumentException $e) {
            return response()->json(
                ['message' => $e->getMessage()],
                $this->importConflictStatus($e),
            );
        } catch (\Throwable $e) {
            report($e);

            app(MaeprodImportLockService::class)->release($data['upload_id']);

            return response()->json([
                'message' => config('app.debug')
                    ? $e->getMessage()
                    : 'Error interno al procesar la carga. Reintenta en unos minutos.',
            ], 500);
        }

        if (! $result['ready']) {
            return response()->json([
                'done' => false,
                'received' => (int) $data['chunk_index'] + 1,
                'total' => (int) $data['total_chunks'],
            ]);
        }

        return response()->json([
            'done' => true,
            'mode' => $result['mode'] ?? 'template',
            'upload_id' => $result['upload_id'],
            'pending_parse' => $result['pending_parse'] ?? false,
            'stream_mode' => $result['stream_mode'] ?? false,
            'ready_to_process' => $result['ready_to_process'] ?? false,
            'batch_count' => $result['batch_count'] ?? null,
            'columns' => $result['columns'] ?? null,
            'total_rows' => $result['total_rows'] ?? null,
            'suggested_mapping' => $result['suggested_mapping'] ?? null,
        ]);
    }

    public function initializeCustomImport(Request $request, MaeprodImportStagingService $staging): JsonResponse
    {
        $data = $request->validate([
            'upload_id' => ['required', 'uuid'],
        ]);

        try {
            $initialized = $staging->initializeFromPending(
                $data['upload_id'],
                (int) $request->user()->id,
            );
        } catch (\InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        } catch (\Throwable $e) {
            report($e);

            return response()->json([
                'message' => config('app.debug')
                    ? $e->getMessage()
                    : 'Error al analizar el archivo Excel.',
            ], 500);
        }

        return response()->json($initialized);
    }

    public function previewImportMapping(Request $request, MaeprodImportStagingService $staging): JsonResponse
    {
        $data = $request->validate([
            'upload_id' => ['required', 'uuid'],
            'mapping' => ['required', 'array'],
        ]);

        try {
            $mapping = $this->normalizeColumnMapping($data['mapping']);
            $preview = $staging->preview(
                $data['upload_id'],
                (int) $request->user()->id,
                $mapping,
            );
        } catch (\InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json($preview);
    }

    public function prepareCustomImport(Request $request, MaeprodImportJobService $importJob): JsonResponse
    {
        $data = $request->validate([
            'upload_id' => ['required', 'uuid'],
            'mapping' => ['required', 'array'],
        ]);

        try {
            $mapping = $this->normalizeColumnMapping($data['mapping']);
            $prepared = $importJob->continuePrepareCustom(
                $data['upload_id'],
                (int) $request->user()->id,
                $mapping,
            );
        } catch (\InvalidArgumentException $e) {
            return response()->json(
                ['message' => $e->getMessage()],
                $this->importConflictStatus($e),
            );
        } catch (\Throwable $e) {
            report($e);

            return response()->json([
                'message' => config('app.debug')
                    ? $e->getMessage()
                    : 'Error al preparar la importación.',
            ], 500);
        }

        return response()->json($prepared);
    }

    public function prepareTemplateImport(Request $request, MaeprodImportJobService $importJob): JsonResponse
    {
        $data = $request->validate([
            'upload_id' => ['required', 'uuid'],
        ]);

        try {
            $prepared = $importJob->continuePrepareTemplate(
                $data['upload_id'],
                (int) $request->user()->id,
            );
        } catch (\InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        } catch (\Throwable $e) {
            report($e);

            app(MaeprodImportLockService::class)->release($data['upload_id']);

            return response()->json([
                'message' => config('app.debug')
                    ? $e->getMessage()
                    : 'Error al analizar el archivo Excel.',
            ], 500);
        }

        return response()->json($prepared);
    }

    public function processImportBatch(Request $request, MaeprodImportJobService $importJob): JsonResponse
    {
        $data = $request->validate([
            'upload_id' => ['required', 'uuid'],
        ]);

        try {
            $progress = $importJob->processNextBatchWithRun(
                $data['upload_id'],
                (int) $request->user()->id,
            );
        } catch (\InvalidArgumentException $e) {
            return response()->json(
                ['message' => $e->getMessage()],
                $this->importConflictStatus($e),
            );
        } catch (\Throwable $e) {
            report($e);

            app(MaeprodImportLockService::class)->release($data['upload_id']);

            return response()->json([
                'message' => config('app.debug')
                    ? $e->getMessage()
                    : 'Error interno al importar productos. Reintenta en unos minutos.',
            ], 500);
        }

        $payload = [
            'finished' => $progress['finished'],
            'processed_batches' => $progress['processed_batches'],
            'total_batches' => $progress['total_batches'],
            'import_mode' => $progress['import_mode'] ?? MaeprodImportJobService::IMPORT_MODE_BATCH,
        ];

        if (isset($progress['processed_rows'])) {
            $payload['processed_rows'] = $progress['processed_rows'];
        }

        if (isset($progress['total_rows'])) {
            $payload['total_rows'] = $progress['total_rows'];
        }

        if (isset($progress['result']) && is_array($progress['result'])) {
            $payload['result'] = [
                'created' => (int) ($progress['result']['created'] ?? 0),
                'updated' => (int) ($progress['result']['updated'] ?? 0),
                'skipped' => (int) ($progress['result']['skipped'] ?? 0),
            ];
        }

        if ($progress['finished'] && isset($progress['run_id'])) {
            $run = MaeprodImportRun::query()->findOrFail($progress['run_id']);
            $payload['redirect'] = app(MaeprodImportRunService::class)->redirectUrlForRun($run);
        }

        return response()->json($payload);
    }

    /**
     * @param  array<string, mixed>  $mapping
     * @return array<string, string>
     */
    protected function normalizeColumnMapping(array $mapping): array
    {
        $normalized = [];

        foreach (MaeprodImportColumnMapping::FIELDS as $field => $meta) {
            $value = trim((string) ($mapping[$field] ?? ''));
            $normalized[$field] = $value !== '' ? $value : '';
        }

        MaeprodImportColumnMapping::validate($normalized);

        return $normalized;
    }

    protected function importConflictStatus(\InvalidArgumentException $exception): int
    {
        return str_contains($exception->getMessage(), 'importación en curso') ? 409 : 422;
    }
}
