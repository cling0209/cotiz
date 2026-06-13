<?php

namespace App\Services;

use App\Models\Maeprod;
use App\Models\Nota;
use App\Models\NotaDetalle;
use App\Support\ProdValorFechaUi;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class NotaDetalleService
{
    public function lineasDeNota(Nota $nota): Collection
    {
        return NotaDetalle::query()
            ->where('nronota', $nota->nronota)
            ->with('producto')
            ->orderBy('orden')
            ->get()
            ->map(function (NotaDetalle $linea) use ($nota) {
                $repetidos = NotaDetalle::query()
                    ->where('nronota', $nota->nronota)
                    ->where('prod_item', $linea->prod_item)
                    ->count();

                [$fechaFmt, $fechaAntigua] = ProdValorFechaUi::textoYAntigua(
                    $linea->producto?->prod_valor_fecha
                );

                return [
                    'linea' => $linea,
                    'prod_nombre' => $linea->producto?->prod_nombre ?? $linea->prod_item,
                    'prod_familia' => $linea->producto?->prod_familia,
                    'prod_imagen' => $linea->producto?->imageUrl(),
                    'image_url' => $linea->producto?->imageUrl(),
                    'prod_item_softland' => $linea->producto?->prod_item_softland ?? '',
                    'prod_item_agile' => $linea->prod_item_agile ?? '',
                    'prod_valor_fecha' => $fechaFmt,
                    'prod_valor_fecha_antigua' => $fechaAntigua,
                    'total' => $linea->lineTotal(),
                    'repetidos' => $repetidos,
                ];
            });
    }

    public function actualizarLinea(
        Nota $nota,
        string $prodItem,
        int $orden,
        array $datos,
        ?string $usuarioUpd = null,
    ): void {
        DB::transaction(function () use ($nota, $prodItem, $orden, $datos, $usuarioUpd) {
            $linea = NotaDetalle::query()
                ->where('nronota', $nota->nronota)
                ->where('prod_item', $prodItem)
                ->where('orden', $orden)
                ->firstOrFail();

            $producto = Maeprod::query()->find($prodItem);
            $prodValor = (int) ($datos['prod_valor'] ?? $linea->prod_valor);
            $cantidad = (int) ($datos['cantidad'] ?? $linea->cantidad);
            $costo = (int) ($datos['prod_valor_costo'] ?? $linea->prod_valor_costo);

            if ($producto) {
                $updates = [];
                if ($producto->prod_valor !== $prodValor) {
                    $updates['prod_valor'] = $prodValor;
                    $updates['prod_valor_fecha'] = now();
                    $updates['prod_user_upd'] = $usuarioUpd;
                }
                if ((int) ($producto->prod_valor_costo ?? 0) !== $costo) {
                    $updates['prod_valor_costo'] = $costo;
                    $updates['prod_valor_fecha'] = now();
                    $updates['prod_user_upd'] = $usuarioUpd;
                }
                if (array_key_exists('prod_item_softland', $datos)) {
                    $softland = trim((string) $datos['prod_item_softland']);
                    if ($softland !== (string) ($producto->prod_item_softland ?? '')) {
                        $updates['prod_item_softland'] = $softland;
                        $updates['prod_item_softland_fecha'] = now();
                    }
                }
                if ($updates !== []) {
                    $producto->update($updates);
                }
            }

            NotaDetalle::query()
                ->where('nronota', $nota->nronota)
                ->where('prod_item', $prodItem)
                ->where('orden', $orden)
                ->update([
                    'prod_valor' => $prodValor,
                    'cantidad' => $cantidad,
                    'prod_valor_costo' => $costo,
                ]);
        });
    }

    public function guardarLineas(Nota $nota, array $lineas, ?string $usuarioUpd = null): void
    {
        foreach ($lineas as $data) {
            if (empty($data['prod_item']) || ! isset($data['orden'])) {
                continue;
            }
            $this->actualizarLinea(
                $nota,
                (string) $data['prod_item'],
                (int) $data['orden'],
                $data,
                $usuarioUpd,
            );
        }
    }

    /**
     * @return array{ok: int, total: int, factor: float}
     */
    public function aplicarFactorPrecioVenta(Nota $nota, float $factor, ?string $usuarioUpd = null): array
    {
        return DB::transaction(function () use ($nota, $factor, $usuarioUpd) {
            $factor = round($factor, 2);
            $nota->update(['factor_precio_venta' => $factor]);

            $lineas = NotaDetalle::query()
                ->where('nronota', $nota->nronota)
                ->with('producto')
                ->get();

            $okCount = 0;
            foreach ($lineas as $linea) {
                $costo = (int) ($linea->prod_valor_costo ?? 0);
                $valorActual = (int) $linea->prod_valor;
                $nuevoValor = $costo > 0 ? (int) round($costo * $factor) : $valorActual;

                $this->actualizarLinea($nota, $linea->prod_item, (int) $linea->orden, [
                    'prod_valor' => $nuevoValor,
                    'cantidad' => $linea->cantidad,
                    'prod_valor_costo' => $costo,
                    'prod_item_softland' => $linea->producto?->prod_item_softland ?? '',
                ], $usuarioUpd);

                $okCount++;
            }

            return ['ok' => $okCount, 'total' => $lineas->count(), 'factor' => $factor];
        });
    }

    public function agregarLinea(Nota $nota, string $prodItem, int $cantidad, int $prodValor, ?int $prodValorCosto = null, ?string $usuarioUpd = null): NotaDetalle
    {
        return DB::transaction(function () use ($nota, $prodItem, $cantidad, $prodValor, $prodValorCosto, $usuarioUpd) {
            $producto = Maeprod::query()->find($prodItem);
            $costo = $prodValorCosto ?? $producto?->prod_valor_costo ?? 0;

            if ($producto) {
                $updates = [];
                if ($producto->prod_valor !== $prodValor) {
                    $updates['prod_valor'] = $prodValor;
                    $updates['prod_valor_fecha'] = now();
                    $updates['prod_user_upd'] = $usuarioUpd;
                }
                if ((int) ($producto->prod_valor_costo ?? 0) !== (int) $costo) {
                    $updates['prod_valor_costo'] = $costo;
                    $updates['prod_valor_fecha'] = now();
                    $updates['prod_user_upd'] = $usuarioUpd;
                }
                if ($updates !== []) {
                    $producto->update($updates);
                }
            }

            $orden = ((int) NotaDetalle::query()
                ->where('nronota', $nota->nronota)
                ->max('orden')) + 1;

            return NotaDetalle::create([
                'nronota' => $nota->nronota,
                'prod_item' => trim($prodItem),
                'prod_valor' => $prodValor,
                'cantidad' => $cantidad,
                'fechahora' => now(),
                'orden' => $orden ?: 1,
                'prod_valor_costo' => $costo,
            ]);
        });
    }

    public function eliminarLinea(Nota $nota, string $prodItem, int $orden): void
    {
        NotaDetalle::query()
            ->where('nronota', $nota->nronota)
            ->where('prod_item', $prodItem)
            ->where('orden', $orden)
            ->delete();
    }

    public function cambiarOrden(Nota $nota, string $prodItem, int $ordenAnterior, int $ordenNuevo): void
    {
        if ($ordenNuevo < 1) {
            throw new \InvalidArgumentException('El valor debe ser mayor a 0.');
        }

        $lineas = NotaDetalle::query()
            ->where('nronota', $nota->nronota)
            ->orderBy('orden')
            ->get()
            ->values();

        $totalLineas = $lineas->count();

        if ($ordenNuevo > $totalLineas) {
            throw new \InvalidArgumentException('El valor debe ser menor o igual al último número de orden.');
        }

        if ($ordenAnterior === $ordenNuevo) {
            return;
        }

        $fromIndex = $lineas->search(
            fn (NotaDetalle $linea) => $linea->prod_item === $prodItem && (int) $linea->orden === $ordenAnterior
        );

        if ($fromIndex === false) {
            throw new \InvalidArgumentException('Línea no encontrada.');
        }

        $moving = $lineas->splice($fromIndex, 1)->first();
        $toIndex = max(0, min($ordenNuevo - 1, $lineas->count()));
        $lineas->splice($toIndex, 0, [$moving]);

        DB::transaction(fn () => $this->persistirOrdenLineas($nota->nronota, $lineas));
    }

    public function moverLineaRelativo(Nota $nota, string $prodItem, int $orden, int $delta): void
    {
        if ($delta === 0) {
            return;
        }

        $this->cambiarOrden($nota, $prodItem, $orden, $orden + $delta);
    }

    /**
     * @param  Collection<int, NotaDetalle>  $lineasOrdenadas
     */
    private function persistirOrdenLineas(int $nronota, Collection $lineasOrdenadas): void
    {
        $payloads = $lineasOrdenadas->map(function (NotaDetalle $linea) {
            return [
                'nronota' => $linea->nronota,
                'prod_item' => $linea->prod_item,
                'prod_valor' => $linea->prod_valor,
                'cantidad' => $linea->cantidad,
                'fechahora' => $linea->fechahora,
                'prod_valor_costo' => $linea->prod_valor_costo,
                'prod_item_agile' => $linea->prod_item_agile,
            ];
        })->all();

        NotaDetalle::query()->where('nronota', $nronota)->delete();

        foreach ($payloads as $index => $data) {
            NotaDetalle::query()->create([
                ...$data,
                'orden' => $index + 1,
            ]);
        }
    }

    public function buscarProductos(?string $term, ?string $familia = null, ?int $limit = null): Collection
    {
        $term = trim((string) $term);
        $minChars = max(1, (int) config('cotiz.buscar_productos_min_chars', 2));

        if (mb_strlen($term) < $minChars) {
            return collect();
        }

        $limit = $limit ?? (int) config('cotiz.buscar_productos_limite', 15);
        $maxLimite = max(1, (int) config('cotiz.buscar_productos_max_limite', 50));
        $limit = min(max(1, $limit), $maxLimite);

        $termLower = mb_strtolower($term);
        $likeTerm = '%'.$termLower.'%';
        $prefixTerm = $termLower.'%';
        $words = preg_split('/\s+/u', $termLower, -1, PREG_SPLIT_NO_EMPTY) ?: [];

        $query = Maeprod::query()
            ->selectRaw('maeprod.*, (
                CASE
                    WHEN LOWER(prod_item) = ? THEN 100
                    WHEN prod_item ILIKE ? THEN 95
                    WHEN prod_nombre ILIKE ? THEN 85
                    WHEN prod_item ILIKE ? THEN 70
                    WHEN prod_nombre ILIKE ? THEN 55
                    ELSE 40
                END
            ) AS relevancia', [
                $termLower,
                $prefixTerm,
                $prefixTerm,
                $likeTerm,
                $likeTerm,
            ])
            ->where(function ($q) use ($likeTerm, $words) {
                $q->where('prod_nombre', 'ilike', $likeTerm)
                    ->orWhere('prod_item', 'ilike', $likeTerm);

                if (count($words) > 1) {
                    $q->orWhere(function ($sub) use ($words) {
                        foreach ($words as $word) {
                            $sub->where('prod_nombre', 'ilike', '%'.$word.'%');
                        }
                    });
                }
            });

        if ($familia) {
            $query->where('prod_familia', trim($familia));
        }

        return $query
            ->orderByDesc('relevancia')
            ->orderBy('prod_valor')
            ->orderBy('prod_nombre')
            ->limit($limit)
            ->get();
    }
}
