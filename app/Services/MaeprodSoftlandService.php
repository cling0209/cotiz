<?php

namespace App\Services;

use App\Enums\MaeprodSoftlandOrigen;
use App\Models\Maeprod;
use App\Models\MaeprodSoftlandAuditoria;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class MaeprodSoftlandService
{
    /**
     * Actualiza Softland en el maestro y registra auditoría si hay cambio.
     *
     * @return bool true si hubo cambio persistido
     */
    public function aplicar(
        Maeprod $producto,
        ?string $valorNuevo,
        ?string $usuario,
        MaeprodSoftlandOrigen $origen,
        ?int $nronota = null,
    ): bool {
        $nuevo = $this->normalizar($valorNuevo);
        $anterior = $this->normalizar($producto->prod_item_softland);

        if ($nuevo === $anterior) {
            return false;
        }

        $producto->update([
            'prod_item_softland' => $nuevo,
            'prod_item_softland_fecha' => now(),
        ]);

        $this->registrar(
            (string) $producto->prod_item,
            $anterior,
            $nuevo,
            $usuario,
            $origen,
            $nronota,
        );

        return true;
    }

    /**
     * Solo auditoría (p. ej. alta con Softland ya persistido, o lote de import).
     */
    public function registrar(
        string $prodItem,
        ?string $valorAnterior,
        ?string $valorNuevo,
        ?string $usuario,
        MaeprodSoftlandOrigen $origen,
        ?int $nronota = null,
    ): ?MaeprodSoftlandAuditoria {
        $prodItem = trim($prodItem);
        $usuario = mb_substr(trim((string) $usuario), 0, 50);
        if ($prodItem === '' || $usuario === '') {
            return null;
        }

        $anterior = $this->normalizar($valorAnterior);
        $nuevo = $this->normalizar($valorNuevo);
        if ($anterior === $nuevo) {
            return null;
        }

        return MaeprodSoftlandAuditoria::query()->create([
            'prod_item' => $prodItem,
            'usuario' => $usuario,
            'fechahora' => now(),
            'valor_anterior' => $anterior,
            'valor_nuevo' => $nuevo,
            'origen' => $origen,
            'nronota' => $nronota !== null && $nronota > 0 ? $nronota : null,
        ]);
    }

    /**
     * @param  list<array{prod_item: string, valor_anterior: ?string, valor_nuevo: ?string}>  $cambios
     */
    public function registrarMuchos(
        array $cambios,
        ?string $usuario,
        MaeprodSoftlandOrigen $origen,
        ?int $nronota = null,
    ): int {
        $usuario = mb_substr(trim((string) $usuario), 0, 50);
        if ($usuario === '' || $cambios === []) {
            return 0;
        }

        $ahora = now();
        $rows = [];
        foreach ($cambios as $cambio) {
            $prodItem = trim((string) ($cambio['prod_item'] ?? ''));
            if ($prodItem === '') {
                continue;
            }
            $anterior = $this->normalizar($cambio['valor_anterior'] ?? null);
            $nuevo = $this->normalizar($cambio['valor_nuevo'] ?? null);
            if ($anterior === $nuevo) {
                continue;
            }
            $rows[] = [
                'prod_item' => $prodItem,
                'usuario' => $usuario,
                'fechahora' => $ahora,
                'valor_anterior' => $anterior,
                'valor_nuevo' => $nuevo,
                'origen' => $origen->value,
                'nronota' => $nronota !== null && $nronota > 0 ? $nronota : null,
            ];
        }

        if ($rows === []) {
            return 0;
        }

        foreach (array_chunk($rows, 500) as $chunk) {
            DB::table('maeprod_softland_auditoria')->insert($chunk);
        }

        return count($rows);
    }

    /**
     * @return Collection<int, MaeprodSoftlandAuditoria>
     */
    public function historial(string $prodItem, int $limit = 50): Collection
    {
        return MaeprodSoftlandAuditoria::query()
            ->where('prod_item', trim($prodItem))
            ->orderByDesc('fechahora')
            ->orderByDesc('id')
            ->limit(max(1, $limit))
            ->get();
    }

    public function normalizar(?string $valor): ?string
    {
        $v = trim((string) $valor);

        return $v === '' ? null : mb_substr($v, 0, 120);
    }
}
