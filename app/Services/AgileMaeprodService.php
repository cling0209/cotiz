<?php

namespace App\Services;

use App\Models\AgileMaeprod;

class AgileMaeprodService
{
    public function registrarSiNoExiste(string $prodItemAgile, string $descripcionAgile): void
    {
        $id = trim($prodItemAgile);
        if ($id === '') {
            return;
        }

        $desc = str_replace("'", '´', trim($descripcionAgile));

        $existente = AgileMaeprod::query()->find($id);
        if ($existente) {
            if ($desc !== '' && $existente->prod_descripcion_agile !== $desc) {
                $existente->update(['prod_descripcion_agile' => $desc]);
            }

            return;
        }

        AgileMaeprod::query()->create([
            'prod_item_agile' => $id,
            'prod_descripcion_agile' => $desc,
            'prod_item' => '',
        ]);
    }

    public function vincularCodigoInterno(string $prodItemAgile, string $prodItem): void
    {
        $agileId = trim($prodItemAgile);
        $codigo = trim($prodItem);
        if ($agileId === '' || $codigo === '') {
            return;
        }

        AgileMaeprod::query()->updateOrCreate(
            ['prod_item_agile' => $agileId],
            ['prod_item' => $codigo]
        );
    }

    public function codigoInternoParaAgile(string $prodItemAgile): ?string
    {
        $row = AgileMaeprod::query()->find(trim($prodItemAgile));

        if (! $row || trim((string) $row->prod_item) === '' || trim((string) $row->prod_item) === '0') {
            return null;
        }

        return trim((string) $row->prod_item);
    }
}
