<?php

namespace App\Http\Controllers\Web\Admin;

use App\Http\Controllers\Controller;
use App\Models\ShippingComunaWeightRate;
use App\Models\ShippingRegionRate;
use App\Models\ShippingSetting;
use App\Services\Admin\ShippingWeightRateChunkUploadService;
use App\Services\Admin\ShippingWeightRateImportJobService;
use App\Services\Admin\ShippingWeightRateImportService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ShippingController extends Controller
{
    public function __construct(protected ShippingWeightRateImportService $rateImport) {}

    public function index(Request $request): View
    {
        $regionComunas = ShippingComunaWeightRate::chileRegionComunasExcludingRm();
        $regions = array_keys($regionComunas);

        $selectedRegion = $request->query('region', $regions[0] ?? '');
        $selectedComuna = $request->query('comuna');

        if ($selectedRegion !== '' && ! array_key_exists($selectedRegion, $regionComunas)) {
            $selectedRegion = $regions[0] ?? '';
        }

        $comunas = $regionComunas[$selectedRegion] ?? [];

        if ($selectedComuna === null || ! in_array($selectedComuna, $comunas, true)) {
            $selectedComuna = $comunas[0] ?? null;
        }

        $comunaRates = collect();

        if ($selectedRegion !== '' && $selectedComuna !== null) {
            $comunaRates = ShippingComunaWeightRate::query()
                ->where('region', $selectedRegion)
                ->where('comuna', $selectedComuna)
                ->orderBy('sort_order')
                ->orderBy('min_weight_kg')
                ->get();
        }

        return view('admin.shipping.index', [
            'rmFlatRate' => ShippingSetting::getFloat('rm_flat_rate', 3990),
            'defaultProductWeight' => ShippingSetting::getFloat('default_product_weight_kg', 1.0),
            'fallbackAdditional' => ShippingSetting::getFloat('fallback_additional_clp', 500),
            'regionRates' => ShippingRegionRate::query()->orderBy('region')->get(),
            'regionComunas' => $regionComunas,
            'selectedRegion' => $selectedRegion,
            'selectedComuna' => $selectedComuna,
            'comunaRates' => $comunaRates,
        ]);
    }

    public function updateSettings(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'rm_flat_rate' => ['required', 'numeric', 'min:0'],
            'default_product_weight_kg' => ['required', 'numeric', 'min:0.001'],
            'fallback_additional_clp' => ['required', 'numeric', 'min:0'],
        ]);

        ShippingSetting::setValue('rm_flat_rate', $data['rm_flat_rate']);
        ShippingSetting::setValue('default_product_weight_kg', $data['default_product_weight_kg']);
        ShippingSetting::setValue('fallback_additional_clp', $data['fallback_additional_clp']);

        return redirect()
            ->route('admin.shipping.index')
            ->with('success', 'Configuración de envío actualizada.');
    }

    public function updateRegionRates(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'regions' => ['required', 'array'],
            'regions.*.flat_rate' => ['required', 'numeric', 'min:0'],
        ]);

        foreach ($data['regions'] as $id => $row) {
            $regionRate = ShippingRegionRate::query()->find($id);

            if (! $regionRate) {
                continue;
            }

            $regionRate->update([
                'flat_rate' => $row['flat_rate'],
                'is_active' => ! empty($row['is_active']),
            ]);
        }

        return redirect()
            ->route('admin.shipping.index')
            ->with('success', 'Tarifas fijas por región actualizadas.');
    }

    public function storeRate(Request $request): RedirectResponse
    {
        $data = $this->validatedRate($request);
        ShippingComunaWeightRate::create($data);

        return redirect()
            ->to($this->shippingComunaTramosUrl($data['region'], $data['comuna']))
            ->with('success', 'Tramo de peso creado.');
    }

    public function updateRate(Request $request, ShippingComunaWeightRate $rate): RedirectResponse
    {
        $data = $this->validatedRate($request, $rate);
        $rate->update($data);

        return redirect()
            ->to($this->shippingComunaTramosUrl($rate->region, $rate->comuna))
            ->with('success', 'Tramo de peso actualizado.');
    }

    public function destroyRate(ShippingComunaWeightRate $rate): RedirectResponse
    {
        $region = $rate->region;
        $comuna = $rate->comuna;
        $rate->delete();

        return redirect()
            ->to($this->shippingComunaTramosUrl($region, $comuna))
            ->with('success', 'Tramo de peso eliminado.');
    }

    public function importForm(): View
    {
        return view('admin.shipping.import');
    }

    public function downloadImportTemplate(): StreamedResponse
    {
        $content = $this->rateImport->generateTemplateCsv();

        return response()->streamDownload(
            fn () => print($content),
            'plantilla_tramos_peso.csv',
            ['Content-Type' => 'text/csv; charset=UTF-8']
        );
    }

    public function exportRates(): StreamedResponse
    {
        return $this->rateImport->exportCsvResponse();
    }

    public function storeImportChunk(Request $request, ShippingWeightRateChunkUploadService $chunkUpload): JsonResponse
    {
        set_time_limit(120);

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

    public function processImportBatch(Request $request, ShippingWeightRateImportJobService $importJob): JsonResponse
    {
        set_time_limit(120);

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
                    : 'Error interno al importar tramos. Reintenta en unos minutos.',
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
            session()->flash('error', 'No se importó ningún tramo.');
            session()->flash('import_errors', array_slice($result['errors'], 0, 30));

            return route('admin.shipping.import');
        }

        session()->flash('success', 'Importación completada: '.implode(', ', $parts).'.');

        if ($result['errors'] !== []) {
            session()->flash('import_errors', array_slice($result['errors'], 0, 30));

            if (count($result['errors']) > 30) {
                session()->flash('error', 'Algunas filas fallaron. Se muestran los primeros 30 errores.');
            }
        }

        return $this->shippingComunaTramosUrl();
    }

    protected function shippingComunaTramosUrl(?string $region = null, ?string $comuna = null): string
    {
        $params = array_filter([
            'region' => $region,
            'comuna' => $comuna,
        ], fn (mixed $value): bool => $value !== null && $value !== '');

        $url = route('admin.shipping.index', $params);

        return str_contains($url, '#') ? $url : $url.'#comuna-tramos';
    }

    protected function validatedRate(Request $request, ?ShippingComunaWeightRate $existing = null): array
    {
        $data = $request->validate([
            'region' => ['required', 'string', 'max:80'],
            'comuna' => ['required', 'string', 'max:80'],
            'min_weight_kg' => ['required', 'numeric', 'min:0'],
            'max_weight_kg' => ['nullable', 'numeric', 'gt:min_weight_kg'],
            'price' => ['required', 'numeric', 'min:0'],
            'sort_order' => [
                'nullable',
                'integer',
                'min:0',
                Rule::unique('shipping_comuna_weight_rates', 'sort_order')
                    ->where(fn ($query) => $query
                        ->where('region', $request->input('region'))
                        ->where('comuna', $request->input('comuna')))
                    ->ignore($existing?->id),
            ],
        ], [
            'sort_order.unique' => 'Ya existe otro tramo con ese orden en esta comuna.',
        ]);

        if (! ShippingComunaWeightRate::isValidComuna($data['region'], $data['comuna'])) {
            throw \Illuminate\Validation\ValidationException::withMessages([
                'comuna' => 'La comuna no pertenece a la región seleccionada.',
            ]);
        }

        $minWeight = (float) $data['min_weight_kg'];
        $maxWeight = isset($data['max_weight_kg']) ? (float) $data['max_weight_kg'] : null;

        $data['label'] = ShippingComunaWeightRate::formatLabelFromWeight($minWeight, $maxWeight);
        $data['is_active'] = $request->boolean('is_active');
        $data['sort_order'] = $data['sort_order'] ?? 0;

        return $data;
    }
}
