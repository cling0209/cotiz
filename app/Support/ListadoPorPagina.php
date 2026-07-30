<?php

namespace App\Support;

use Illuminate\Http\Request;

final class ListadoPorPagina
{
    public const DEFAULT = 20;

    /** @var list<int> */
    public const OPCIONES = [20, 40, 60];

    public static function normalizar(int $valor): int
    {
        return in_array($valor, self::OPCIONES, true) ? $valor : self::DEFAULT;
    }

    public static function clavePantalla(Request $request, ?string $screenKey = null): string
    {
        $key = $screenKey ?? $request->route()?->getName() ?? $request->path();

        return 'listado_por_pagina.'.preg_replace('/[^a-zA-Z0-9._-]+/', '_', $key);
    }

    public static function resolver(Request $request, ?string $screenKey = null): int
    {
        $sessionKey = self::clavePantalla($request, $screenKey);

        if ($request->has('por_pagina')) {
            $valor = self::normalizar((int) $request->input('por_pagina'));
            $request->session()->put($sessionKey, $valor);

            return $valor;
        }

        $guardado = $request->session()->get($sessionKey);
        if ($guardado !== null) {
            return self::normalizar((int) $guardado);
        }

        return self::DEFAULT;
    }
}
