<?php

namespace App\Services;

use App\Models\Maeprod;
use App\Models\MaeprodFraseBusqueda;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class MaeprodFraseBusquedaService
{
    public function __construct(
        protected MaeprodBusquedaSimilitudService $busquedaSimilitud,
    ) {}

    /**
     * @param  list<int>  $regiones  vacío = todas
     */
    public function agregar(string $frase, ?Maeprod $producto = null, array $regiones = [], ?int $createdBy = null): MaeprodFraseBusqueda
    {
        $fraseDisplay = $this->normalizarDisplay($frase);
        $fraseNorm = $this->busquedaSimilitud->normalizarTexto($fraseDisplay);

        if (mb_strlen($fraseDisplay) < 2) {
            throw ValidationException::withMessages([
                'frase' => 'La frase debe tener al menos 2 caracteres.',
            ]);
        }

        if ($fraseNorm === '') {
            throw ValidationException::withMessages([
                'frase' => 'La frase no es válida para buscar en Mercado Público.',
            ]);
        }

        if (MaeprodFraseBusqueda::query()->where('frase_norm', $fraseNorm)->exists()) {
            throw ValidationException::withMessages([
                'frase' => 'Esa palabra clave ya está registrada.',
            ]);
        }

        return DB::transaction(function () use ($fraseDisplay, $fraseNorm, $producto, $regiones, $createdBy) {
            $row = MaeprodFraseBusqueda::query()->create([
                'prod_item' => $producto?->prod_item,
                'frase' => mb_substr($fraseDisplay, 0, 200),
                'frase_norm' => mb_substr($fraseNorm, 0, 200),
                'created_by' => $createdBy,
            ]);

            foreach ($regiones as $codigo) {
                $codigo = (int) $codigo;
                if ($codigo > 0) {
                    $row->regiones()->create(['region_codigo' => $codigo]);
                }
            }

            return $row;
        });
    }

    public function eliminar(MaeprodFraseBusqueda $frase): void
    {
        $frase->delete();
    }

    /**
     * @param  list<int>  $codigos  vacío = todas las regiones
     */
    public function syncRegiones(MaeprodFraseBusqueda $frase, array $codigos): void
    {
        DB::transaction(function () use ($frase, $codigos) {
            $frase->regiones()->delete();

            foreach ($codigos as $codigo) {
                $codigo = (int) $codigo;
                if ($codigo > 0) {
                    $frase->regiones()->create(['region_codigo' => $codigo]);
                }
            }
        });
    }

    private function normalizarDisplay(string $frase): string
    {
        return trim(preg_replace('/\s+/u', ' ', $frase) ?? $frase);
    }
}
