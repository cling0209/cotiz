<?php

namespace App\Services;

use App\Models\Maeprod;
use App\Models\MaeprodFraseBusqueda;
use Illuminate\Validation\ValidationException;

class MaeprodFraseBusquedaService
{
    public function __construct(
        protected MaeprodBusquedaSimilitudService $busquedaSimilitud,
    ) {}

    public function agregar(Maeprod $producto, string $frase): MaeprodFraseBusqueda
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

        $existe = MaeprodFraseBusqueda::query()
            ->where('prod_item', $producto->prod_item)
            ->where('frase_norm', $fraseNorm)
            ->exists();

        if ($existe) {
            throw ValidationException::withMessages([
                'frase' => 'Esa frase de búsqueda ya está en este producto.',
            ]);
        }

        return MaeprodFraseBusqueda::query()->create([
            'prod_item' => $producto->prod_item,
            'frase' => mb_substr($fraseDisplay, 0, 200),
            'frase_norm' => mb_substr($fraseNorm, 0, 200),
        ]);
    }

    public function eliminar(Maeprod $producto, MaeprodFraseBusqueda $frase): void
    {
        if ($frase->prod_item !== $producto->prod_item) {
            abort(404);
        }

        $frase->delete();
    }

    private function normalizarDisplay(string $frase): string
    {
        return trim(preg_replace('/\s+/u', ' ', $frase) ?? $frase);
    }
}
