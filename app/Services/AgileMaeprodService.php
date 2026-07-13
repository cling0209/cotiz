<?php

namespace App\Services;

use App\Enums\VinculoOrigen;
use App\Models\AgileMaeprod;
use App\Support\AgileDescripcion;

class AgileMaeprodService
{
    public function __construct(
        protected AgileVinculoAprendizajeService $vinculoAprendizaje,
    ) {}

    public function registrarSiNoExiste(string $prodItemAgile, string $descripcionAgile): void
    {
        $id = trim($prodItemAgile);
        $desc = AgileDescripcion::paraMaeprod($descripcionAgile);
        if ($desc === null) {
            return;
        }

        $hash = $this->vinculoAprendizaje->hashDescripcion($desc);

        if ($hash !== '') {
            $porHash = AgileMaeprod::query()->where('descripcion_norm_hash', $hash)->first();
            if ($porHash) {
                $updates = [];
                if ($porHash->prod_descripcion_agile !== $desc) {
                    $updates['prod_descripcion_agile'] = $desc;
                }
                if ($id !== '' && $porHash->prod_codigo_categoria_mp !== $id) {
                    $updates['prod_codigo_categoria_mp'] = mb_substr($id, 0, 50);
                }
                if ($updates !== []) {
                    $porHash->update($updates);
                }

                return;
            }
        }

        $pk = $hash !== '' ? 'desc:'.substr($hash, 0, 43) : mb_substr($id, 0, 50);
        if ($pk === '') {
            return;
        }

        $existente = AgileMaeprod::query()->find($pk);
        if ($existente) {
            $updates = [];
            if ($desc !== null && $existente->prod_descripcion_agile !== $desc) {
                $updates['prod_descripcion_agile'] = $desc;
            }
            if ($hash !== '' && $existente->descripcion_norm_hash !== $hash) {
                $updates['descripcion_norm_hash'] = $hash;
            }
            if ($id !== '' && $existente->prod_codigo_categoria_mp !== $id) {
                $updates['prod_codigo_categoria_mp'] = mb_substr($id, 0, 50);
            }
            if ($updates !== []) {
                $existente->update($updates);
            }

            return;
        }

        AgileMaeprod::query()->create([
            'prod_item_agile' => mb_substr($pk, 0, 50),
            'prod_descripcion_agile' => $desc,
            'prod_item' => '',
            'descripcion_norm_hash' => $hash !== '' ? $hash : null,
            'prod_codigo_categoria_mp' => $id !== '' ? mb_substr($id, 0, 50) : null,
        ]);
    }

    public function vincularCodigoInterno(
        string $prodItemAgile,
        string $prodItem,
        ?string $usuario = null,
        VinculoOrigen $origen = VinculoOrigen::SISTEMA,
        ?int $nronota = null,
    ): void {
        $this->vincularCodigoInternoConDescripcion($prodItemAgile, $prodItem, null, null, $usuario, $origen, $nronota);
    }

    public function vincularCodigoInternoConDescripcion(
        string $prodItemAgile,
        string $prodItem,
        ?string $descripcionAgile,
        ?string $codigoCategoriaMp = null,
        ?string $usuario = null,
        VinculoOrigen $origen = VinculoOrigen::SISTEMA,
        ?int $nronota = null,
    ): void {
        $codigo = trim($prodItem);
        if ($codigo === '') {
            return;
        }

        $desc = trim((string) $descripcionAgile);
        if ($desc !== '') {
            $this->vinculoAprendizaje->guardarAprendizaje(
                $desc,
                $codigo,
                $codigoCategoriaMp,
                null,
                $usuario,
                $origen,
                $nronota,
            );

            return;
        }

        $agileId = trim($prodItemAgile);
        if ($agileId === '') {
            return;
        }

        $usuario = $usuario !== null ? mb_substr(trim($usuario), 0, 100) : null;

        AgileMaeprod::query()->updateOrCreate(
            ['prod_item_agile' => $agileId],
            [
                'prod_item' => $codigo,
                'vinculado_por' => ($usuario !== null && $usuario !== '') ? $usuario : null,
                'vinculado_en' => now(),
                'vinculado_origen' => $origen->value,
                'vinculado_nota' => ($nronota !== null && $nronota > 0) ? $nronota : null,
            ],
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

    public function codigoInternoParaDescripcion(string $descripcion): ?string
    {
        $aprendido = $this->vinculoAprendizaje->buscarCodigoAprendido($descripcion);

        return $aprendido['prod_item'] ?? null;
    }

    public function codigoInternoParaLinea(?string $prodItemAgile, ?string $descripcionAgile): ?string
    {
        $desc = trim((string) $descripcionAgile);
        if ($desc !== '') {
            $porDescripcion = $this->codigoInternoParaDescripcion($desc);
            if ($porDescripcion !== null) {
                return $porDescripcion;
            }
        }

        $agileId = trim((string) $prodItemAgile);
        if ($agileId === '') {
            return null;
        }

        return $this->codigoInternoParaAgile($agileId);
    }
}
