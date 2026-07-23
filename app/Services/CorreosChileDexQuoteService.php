<?php

namespace App\Services;

use App\Models\CorreosChileDexTarifa;
use RuntimeException;

class CorreosChileDexQuoteService
{
    public const ORIGEN_DEFAULT = 'SANTIAGO';

    /**
     * @return array{origenes: list<string>, destinos: list<array{origen: string, destino: string, destino_key: string}>}
     */
    public function catalogo(): array
    {
        $rows = CorreosChileDexTarifa::query()
            ->orderBy('origen')
            ->orderBy('destino')
            ->get(['origen', 'destino', 'destino_key']);

        $origenes = $rows->pluck('origen')
            ->map(fn ($o) => trim((string) $o))
            ->filter()
            ->unique(fn (string $o) => CorreosChileDexTarifa::normalizeDestinoKey($o))
            ->values()
            ->all();

        if ($origenes === []) {
            $origenes = [self::ORIGEN_DEFAULT];
        } elseif (! collect($origenes)->contains(
            fn (string $o) => CorreosChileDexTarifa::normalizeDestinoKey($o) === self::ORIGEN_DEFAULT
        )) {
            array_unshift($origenes, self::ORIGEN_DEFAULT);
        }

        $destinos = $rows->map(fn (CorreosChileDexTarifa $row) => [
            'origen' => (string) $row->origen,
            'destino' => (string) $row->destino,
            'destino_key' => (string) $row->destino_key,
        ])->values()->all();

        return [
            'origenes' => array_values($origenes),
            'destinos' => $destinos,
            'origen_default' => self::ORIGEN_DEFAULT,
        ];
    }

    /**
     * Toma el valor de la celda del tramo (tal cual el mantenedor) y lo usa como
     * recargo a sumar al unitario. El tramo es el mayor cuyo kg no supera el peso
     * (ej. 54 kg → columna 50 → $540).
     *
     * @return array{
     *   origen: string,
     *   destino: string,
     *   peso_kg: float,
     *   tramo_kg: string,
     *   precio_base: int,
     *   recargo_pct: ?int,
     *   precio: int,
     *   tarifas: array<string, int>
     * }
     */
    public function cotizar(string $origen, string $destino, float $pesoKg): array
    {
        $pesoKg = round($pesoKg, 3);
        if ($pesoKg <= 0) {
            throw new RuntimeException('Indique un peso mayor a 0 kg.');
        }

        $row = $this->buscarTarifa($origen, $destino);
        if ($row === null) {
            throw new RuntimeException('No hay tarifa DEX para ese origen y destino. Verifique la comuna o importe la tarifa Correos Chile.');
        }

        $tarifas = is_array($row->tarifas) ? $row->tarifas : [];
        if ($tarifas === []) {
            throw new RuntimeException('La tarifa del destino no tiene tramos de peso.');
        }

        [$tramoKey, $precioBase] = $this->resolverTramo($tarifas, $pesoKg);
        $recargo = $row->recargo_pct !== null && (int) $row->recargo_pct > 0
            ? (int) $row->recargo_pct
            : null;
        $precio = $precioBase;
        if ($recargo !== null) {
            $precio = (int) round($precioBase * (1 + ($recargo / 100)));
        }

        return [
            'origen' => (string) $row->origen,
            'destino' => (string) $row->destino,
            'peso_kg' => $pesoKg,
            'tramo_kg' => $tramoKey,
            'precio_base' => $precioBase,
            'recargo_pct' => $recargo,
            'precio' => $precio,
            'tarifas' => array_map('intval', $tarifas),
        ];
    }

    public function buscarTarifa(string $origen, string $destino): ?CorreosChileDexTarifa
    {
        $origenKey = CorreosChileDexTarifa::normalizeDestinoKey($origen);
        $destinoKey = CorreosChileDexTarifa::normalizeDestinoKey($destino);

        if ($origenKey === '' || $destinoKey === '') {
            return null;
        }

        $candidatos = CorreosChileDexTarifa::query()
            ->where('destino_key', $destinoKey)
            ->get();

        if ($candidatos->isEmpty()) {
            // Coincidencia parcial por destino (ej. texto de comuna vs nombre tarifa).
            $candidatos = CorreosChileDexTarifa::query()
                ->where(function ($q) use ($destinoKey) {
                    $q->where('destino_key', 'like', '%'.$destinoKey.'%')
                        ->orWhere('destino_key', 'like', $destinoKey.'%');
                })
                ->limit(20)
                ->get();
        }

        $match = $candidatos->first(
            fn (CorreosChileDexTarifa $row) => CorreosChileDexTarifa::normalizeDestinoKey((string) $row->origen) === $origenKey
        );

        return $match ?? $candidatos->first();
    }

    /**
     * Elige el mayor tramo cuyo tope (kg) no supera el peso; si el peso es menor
     * que el primer tramo, usa el primero. Así 54 kg → columna 50 ($540).
     *
     * @param  array<string, mixed>  $tarifas
     * @return array{0: string, 1: int}
     */
    public function resolverTramo(array $tarifas, float $pesoKg): array
    {
        $ordenados = [];
        foreach ($tarifas as $key => $precio) {
            if (! is_numeric($key) || $precio === null || $precio === '') {
                continue;
            }
            $ordenados[] = [
                'key' => (string) $key,
                'kg' => (float) $key,
                'precio' => (int) round((float) $precio),
            ];
        }

        if ($ordenados === []) {
            throw new RuntimeException('No se encontraron tramos de peso válidos.');
        }

        usort($ordenados, fn (array $a, array $b) => $a['kg'] <=> $b['kg']);

        $elegido = null;
        foreach ($ordenados as $tramo) {
            if ($tramo['kg'] <= $pesoKg + 0.0001) {
                $elegido = $tramo;
            }
        }

        if ($elegido === null) {
            $elegido = $ordenados[0];
        }

        return [$elegido['key'], $elegido['precio']];
    }
}
