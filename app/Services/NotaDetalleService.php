<?php

namespace App\Services;

use App\Models\Maeprod;
use App\Models\Nota;
use App\Models\NotaDetalle;
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

                return [
                    'linea' => $linea,
                    'prod_nombre' => $linea->producto?->prod_nombre ?? $linea->prod_item,
                    'prod_familia' => $linea->producto?->prod_familia,
                    'prod_imagen' => $linea->producto?->imageUrl(),
                    'prod_item_softland' => $linea->producto?->prod_item_softland,
                    'prod_valor_fecha' => $linea->producto?->prod_valor_fecha?->format('d/m/Y'),
                    'total' => $linea->lineTotal(),
                    'repetidos' => $repetidos,
                ];
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

    public function buscarProductos(?string $term, ?string $familia = null, int $limit = 20): Collection
    {
        $query = Maeprod::query();

        if ($term) {
            $like = '%'.mb_strtolower(trim($term)).'%';
            $query->where(function ($q) use ($like) {
                $q->whereRaw('LOWER(prod_nombre) LIKE ?', [$like])
                    ->orWhereRaw('LOWER(prod_item) LIKE ?', [$like]);
            });
        }

        if ($familia) {
            $query->where('prod_familia', trim($familia));
        }

        return $query->orderBy('prod_nombre')->limit($limit)->get();
    }
}
