<?php

namespace App\Services\Admin;

use App\Models\ShippingComunaWeightRate;
use App\Services\ChileLocationCatalog;
use App\Services\ProductImportService;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ShippingWeightRateImportService
{
    public function __construct(protected ProductImportService $csv) {}

    /**
     * @return list<string>
     */
    public function templateHeaders(): array
    {
        return [
            'codigo_comuna',
            'comuna (no editar)',
            'region (no editar)',
            'etiqueta (no editar)',
            'peso_min_kg',
            'peso_max_kg',
            'adicional_clp',
            'orden',
            'activo',
        ];
    }

    public function generateTemplateCsv(): string
    {
        $handle = fopen('php://temp', 'r+');
        fwrite($handle, "\xEF\xBB\xBF");
        fputcsv($handle, $this->templateHeaders(), ';');

        foreach (ChileLocationCatalog::allComunasExcludingRm() as $comuna) {
            foreach (ShippingComunaWeightRate::defaultBands() as $band) {
                fputcsv($handle, [
                    $comuna['codigo'],
                    $comuna['nombre'],
                    $comuna['region'],
                    ShippingComunaWeightRate::formatLabelFromWeight($band['min'], $band['max']),
                    $this->formatDecimal($band['min']),
                    $band['max'] !== null ? $this->formatDecimal($band['max']) : '',
                    number_format((float) $band['price'], 0, '', ''),
                    (string) $band['sort'],
                    '1',
                ], ';');
            }
        }

        rewind($handle);
        $content = stream_get_contents($handle) ?: '';
        fclose($handle);

        return $content;
    }

    public function exportCsvResponse(): StreamedResponse
    {
        return response()->streamDownload(function () {
            $handle = fopen('php://output', 'w');
            fwrite($handle, "\xEF\xBB\xBF");
            fputcsv($handle, array_merge(['id'], $this->templateHeaders()), ';');

            ShippingComunaWeightRate::query()
                ->orderBy('region')
                ->orderBy('comuna')
                ->orderBy('sort_order')
                ->orderBy('min_weight_kg')
                ->chunk(500, function ($rates) use ($handle) {
                    foreach ($rates as $rate) {
                        $codigo = ChileLocationCatalog::lookupCutForComuna($rate->region, $rate->comuna) ?? '';

                        fputcsv($handle, [
                            $rate->id,
                            $codigo,
                            $rate->comuna,
                            $rate->region,
                            ShippingComunaWeightRate::formatLabelFromWeight(
                                (float) $rate->min_weight_kg,
                                $rate->max_weight_kg !== null ? (float) $rate->max_weight_kg : null,
                            ),
                            $this->formatDecimal($rate->min_weight_kg),
                            $rate->max_weight_kg !== null ? $this->formatDecimal($rate->max_weight_kg) : '',
                            number_format((float) $rate->price, 0, '', ''),
                            $rate->sort_order,
                            $rate->is_active ? '1' : '0',
                        ], ';');
                    }
                });

            fclose($handle);
        }, 'tramos_peso_envio_'.now()->format('Y-m-d').'.csv', [
            'Content-Type' => 'text/csv; charset=UTF-8',
        ]);
    }

    /**
     * @return array{created: int, updated: int, skipped: int, errors: list<string>}
     */
    public function importFromUploadedFile(UploadedFile $file): array
    {
        $rows = $this->csv->parseUploadedCsv($file);

        if ($rows === []) {
            return [
                'created' => 0,
                'updated' => 0,
                'skipped' => 0,
                'errors' => ['El archivo está vacío o no tiene filas válidas.'],
            ];
        }

        $staging = [];
        $errors = [];
        $skipped = 0;
        $regionComunaPairs = [];

        foreach ($rows as $lineNumber => $row) {
            $displayLine = $lineNumber + 2;
            $normalized = $this->normalizeRow($row);
            $location = $this->resolveLocation($normalized);

            if ($location === null) {
                $errors[] = 'Fila '.$displayLine.': código de comuna inválido o corresponde a RM (no aplica).';
                $skipped++;

                continue;
            }

            $normalized['region'] = $location['region'];
            $normalized['comuna'] = $location['comuna'];

            $validator = Validator::make($normalized, [
                'id' => ['nullable', 'integer', 'exists:shipping_comuna_weight_rates,id'],
                'region' => ['required', 'string', 'max:80'],
                'comuna' => ['required', 'string', 'max:80'],
                'etiqueta' => ['nullable', 'string', 'max:120'],
                'peso_min_kg' => ['required', 'numeric', 'min:0'],
                'peso_max_kg' => ['nullable', 'numeric', 'gt:peso_min_kg'],
                'adicional_clp' => ['required', 'numeric', 'min:0'],
                'orden' => ['nullable', 'integer', 'min:0'],
                'activo' => ['nullable'],
            ]);

            if ($validator->fails()) {
                $errors[] = 'Fila '.$displayLine.': '.$validator->errors()->first();
                $skipped++;

                continue;
            }

            $data = $validator->validated();
            $minWeight = (float) $data['peso_min_kg'];
            $maxWeight = isset($data['peso_max_kg']) ? (float) $data['peso_max_kg'] : null;

            $staging[] = [
                'id' => $data['id'] ?? null,
                'payload' => [
                    'region' => $data['region'],
                    'comuna' => $data['comuna'],
                    'label' => ShippingComunaWeightRate::formatLabelFromWeight($minWeight, $maxWeight),
                    'min_weight_kg' => $minWeight,
                    'max_weight_kg' => $maxWeight,
                    'price' => $data['adicional_clp'],
                    'sort_order' => $data['orden'] ?? 0,
                    'is_active' => $this->parseBoolean($data['activo'] ?? null, true),
                ],
                'lookup_key' => $this->rateLookupKey($data['region'], $data['comuna'], $minWeight, $maxWeight),
            ];

            $regionComunaPairs[$data['region']."\0".$data['comuna']] = [
                'region' => $data['region'],
                'comuna' => $data['comuna'],
            ];
        }

        if ($staging === []) {
            return [
                'created' => 0,
                'updated' => 0,
                'skipped' => $skipped,
                'errors' => $errors,
            ];
        }

        $existingById = [];
        $existingByRange = [];

        $query = ShippingComunaWeightRate::query();

        $query->where(function ($builder) use ($regionComunaPairs) {
            foreach ($regionComunaPairs as $pair) {
                $builder->orWhere(function ($nested) use ($pair) {
                    $nested->where('region', $pair['region'])
                        ->where('comuna', $pair['comuna']);
                });
            }
        });

        foreach ($query->get() as $rate) {
            $existingById[$rate->id] = $rate;
            $existingByRange[$this->rateLookupKey(
                $rate->region,
                $rate->comuna,
                (float) $rate->min_weight_kg,
                $rate->max_weight_kg !== null ? (float) $rate->max_weight_kg : null,
            )] = $rate;
        }

        $created = 0;
        $updated = 0;

        DB::transaction(function () use ($staging, &$existingById, &$existingByRange, &$created, &$updated) {
            foreach ($staging as $item) {
                $rate = null;

                if (! empty($item['id'])) {
                    $rate = $existingById[$item['id']] ?? null;
                }

                if (! $rate) {
                    $rate = $existingByRange[$item['lookup_key']] ?? null;
                }

                if ($rate) {
                    $rate->update($item['payload']);
                    $updated++;
                } else {
                    $rate = ShippingComunaWeightRate::create($item['payload']);
                    $created++;
                    $existingById[$rate->id] = $rate;
                    $existingByRange[$item['lookup_key']] = $rate;
                }
            }
        });

        return compact('created', 'updated', 'skipped', 'errors');
    }

    protected function rateLookupKey(string $region, string $comuna, float $minWeight, ?float $maxWeight): string
    {
        return $region."\0".$comuna."\0".$this->formatDecimal($minWeight)."\0".(
            $maxWeight === null ? 'null' : $this->formatDecimal($maxWeight)
        );
    }

    /**
     * @param  array<string, string>  $row
     * @return array<string, mixed>
     */
    protected function normalizeRow(array $row): array
    {
        $aliases = [
            'label' => 'etiqueta',
            'min_weight_kg' => 'peso_min_kg',
            'max_weight_kg' => 'peso_max_kg',
            'price' => 'adicional_clp',
            'sort_order' => 'orden',
            'is_active' => 'activo',
            'codigo' => 'codigo_comuna',
            'cut' => 'codigo_comuna',
            'comuna_no_editar' => 'comuna_referencia',
            'comuna_(no_editar)' => 'comuna_referencia',
            'region_no_editar' => 'region_referencia',
            'region_(no_editar)' => 'region_referencia',
            'etiqueta_(no_editar)' => 'etiqueta_referencia',
            'etiqueta_no_editar' => 'etiqueta_referencia',
        ];

        $normalized = [];

        foreach ($row as $key => $value) {
            $header = Str::lower(trim(str_replace([' ', '-'], '_', $key)));
            $header = preg_replace('/_+/', '_', $header) ?? $header;
            $header = $aliases[$header] ?? $header;
            $normalized[$header] = trim((string) $value);
        }

        if (($normalized['peso_max_kg'] ?? '') === '') {
            $normalized['peso_max_kg'] = null;
        }

        if (($normalized['id'] ?? '') === '') {
            unset($normalized['id']);
        }

        return $normalized;
    }

    /**
     * @param  array<string, mixed>  $normalized
     * @return array{region: string, comuna: string}|null
     */
    protected function resolveLocation(array $normalized): ?array
    {
        $codigo = trim((string) ($normalized['codigo_comuna'] ?? ''));

        if ($codigo !== '') {
            if (ChileLocationCatalog::isMetropolitanaCut($codigo)) {
                return null;
            }

            $resolved = ChileLocationCatalog::resolveByCut($codigo);

            return $resolved !== null
                ? ['region' => $resolved['region'], 'comuna' => $resolved['comuna']]
                : null;
        }

        $region = trim((string) ($normalized['region'] ?? ''));
        $comuna = trim((string) ($normalized['comuna'] ?? ''));

        if ($region !== '' && $comuna !== '' && ShippingComunaWeightRate::isValidComuna($region, $comuna)) {
            return ['region' => $region, 'comuna' => $comuna];
        }

        return null;
    }

    protected function parseBoolean(?string $value, bool $default): bool
    {
        if ($value === null || trim($value) === '') {
            return $default;
        }

        return in_array(Str::lower(trim($value)), ['1', 'true', 'si', 'sí', 'yes', 'on'], true);
    }

    protected function formatDecimal(mixed $value): string
    {
        return rtrim(rtrim(number_format((float) $value, 3, '.', ''), '0'), '.');
    }
}
