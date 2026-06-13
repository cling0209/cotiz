<?php

namespace App\Http\Controllers\Web\Admin;

use App\Http\Controllers\Controller;
use App\Models\Maeprod;
use App\Services\MaeprodAdminService;
use App\Services\MaeprodChunkUploadService;
use App\Services\MaeprodImportJobService;
use App\Services\MaeprodImportService;
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

    public function importForm(): View
    {
        return view('admin.maeprod.import');
    }

    public function downloadImportTemplate(): StreamedResponse
    {
        return $this->importService->templateCsvDownloadResponse();
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
            'chunk' => ['required', 'file', 'max:7168'],
        ]);

        try {
            $result = $chunkUpload->storeChunk(
                $data['upload_id'],
                (int) $data['chunk_index'],
                (int) $data['total_chunks'],
                $data['original_name'],
                $request->file('chunk'),
                (int) $request->user()->id,
                $request->user()->username,
            );
        } catch (\InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        } catch (\Throwable $e) {
            report($e);

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
            'upload_id' => $result['upload_id'],
            'batch_count' => $result['batch_count'],
        ]);
    }

    public function processImportBatch(Request $request, MaeprodImportJobService $importJob): JsonResponse
    {
        $data = $request->validate([
            'upload_id' => ['required', 'uuid'],
        ]);

        try {
            $progress = $importJob->processNextBatch(
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
                    : 'Error interno al importar productos. Reintenta en unos minutos.',
            ], 500);
        }

        $payload = [
            'finished' => $progress['finished'],
            'processed_batches' => $progress['processed_batches'],
            'total_batches' => $progress['total_batches'],
        ];

        if ($progress['finished']) {
            $payload['redirect'] = $this->flashImportResultAndGetRedirectUrl($progress['result']);
        }

        return response()->json($payload);
    }

    /**
     * @param  array{created: int, updated: int, skipped: int, errors: list<string>}  $result
     */
    protected function flashImportResultAndGetRedirectUrl(array $result): string
    {
        $parts = [];

        if ($result['created'] > 0) {
            $parts[] = $result['created'].' creado(s)';
        }

        if ($result['updated'] > 0) {
            $parts[] = $result['updated'].' actualizado(s)';
        }

        if ($parts === []) {
            session()->flash('error', 'No se importó ningún producto.');
            session()->flash('import_errors', array_slice($result['errors'], 0, 20));

            return route('admin.productos.import');
        }

        session()->flash('success', 'Importación completada: '.implode(', ', $parts).'.');

        if ($result['skipped'] > 0) {
            session()->flash('warning', $result['skipped'].' fila(s) omitida(s).');
        }

        if ($result['errors'] !== []) {
            session()->flash('import_errors', array_slice($result['errors'], 0, 20));

            if (count($result['errors']) > 20) {
                session()->flash('error', 'Algunas filas fallaron. Se muestran los primeros 20 errores.');
            }
        }

        return route('admin.productos.index');
    }
}
