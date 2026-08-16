<?php

namespace App\Support;

use Illuminate\Http\Request;

/**
 * Origen + filtros/página para volver al listado desde el formulario de cotización.
 */
final class CotizacionListadoRetorno
{
    public const FROM_OPORTUNIDADES = 'oportunidades';

    public const FROM_LISTADO = 'listado';

    public const FROM_ADJUDICADAS = 'adjudicadas';

    public const SESSION_KEY = 'cotiz.listado_retorno';

    /** Prefijo para no chocar con `codigo` (Compra Ágil a importar) ni con campos del formulario. */
    public const CLAVES_OPORTUNIDADES = [
        'op_region',
        'op_organismo',
        'op_palabra_clave',
        'op_palabra_clave_aplicada',
        'op_vinculo_desde',
        'op_vinculo_hasta',
        'op_codigo',
        'op_pub_desde',
        'op_pub_hasta',
        'op_cierre_desde',
        'op_cierre_hasta',
        'op_cierre_24h',
        'op_page',
        'op_sort_column',
        'op_sort_direction',
        'op_por_pagina',
    ];

    /**
     * @param  array<string, mixed>  $filtros
     * @return array<string, scalar>
     */
    public static function paraListado(array $filtros, int $page): array
    {
        $q = ['from' => self::FROM_LISTADO];
        foreach (['fechadesde', 'fechahasta', 'cotizacion', 'estado_mp', 'orden_campo', 'orden_dir'] as $key) {
            $valor = trim((string) ($filtros[$key] ?? ''));
            if ($valor !== '') {
                $q[$key] = $valor;
            }
        }
        $nronota = (int) ($filtros['nronota'] ?? 0);
        if ($nronota > 0) {
            $q['buscar_nronota'] = $nronota;
        }
        if ($page > 1) {
            $q['page'] = $page;
        }

        return $q;
    }

    /**
     * @param  array<string, mixed>  $filtros
     * @return array<string, scalar>
     */
    public static function paraAdjudicadas(array $filtros, int $page): array
    {
        $q = ['from' => self::FROM_ADJUDICADAS];
        foreach (['fechaentregadesde', 'fechaentregahasta'] as $key) {
            $valor = trim((string) ($filtros[$key] ?? ''));
            if ($valor !== '') {
                $q[$key] = $valor;
            }
        }
        $nronota = (int) ($filtros['nronota'] ?? 0);
        if ($nronota > 0) {
            $q['buscar_nronota'] = $nronota;
        }
        if ($page > 1) {
            $q['page'] = $page;
        }

        return $q;
    }

    /**
     * @param  array<string, mixed>  $filtros  claves internas (region, pagina, sort_column, …)
     * @return array<string, scalar>
     */
    public static function paraOportunidades(array $filtros, int $page): array
    {
        $q = ['from' => self::FROM_OPORTUNIDADES];
        $mapa = [
            'region' => 'op_region',
            'organismo' => 'op_organismo',
            'palabra_clave' => 'op_palabra_clave',
            'palabra_clave_aplicada' => 'op_palabra_clave_aplicada',
            'vinculo_desde' => 'op_vinculo_desde',
            'vinculo_hasta' => 'op_vinculo_hasta',
            'codigo' => 'op_codigo',
            'pub_desde' => 'op_pub_desde',
            'pub_hasta' => 'op_pub_hasta',
            'cierre_desde' => 'op_cierre_desde',
            'cierre_hasta' => 'op_cierre_hasta',
            'sort_column' => 'op_sort_column',
            'sort_direction' => 'op_sort_direction',
            'por_pagina' => 'op_por_pagina',
        ];
        foreach ($mapa as $interno => $queryKey) {
            $valor = trim((string) ($filtros[$interno] ?? ''));
            if ($valor !== '') {
                $q[$queryKey] = $valor;
            }
        }
        if (! empty($filtros['cierre_24h'])) {
            $q['op_cierre_24h'] = '1';
        }
        if ($page > 1) {
            $q['op_page'] = $page;
        }

        return $q;
    }

    /**
     * @return array<string, scalar>
     */
    public static function query(Request $request): array
    {
        $desdeInput = self::queryDesdeInput($request);
        if ($desdeInput !== []) {
            return $desdeInput;
        }

        if ($request->isMethod('GET')) {
            return [];
        }

        $guardado = $request->hasSession() ? $request->session()->get(self::SESSION_KEY) : null;

        return is_array($guardado) ? self::soloClavesPermitidas($guardado) : [];
    }

    public static function syncSession(Request $request): void
    {
        if (! $request->isMethod('GET') || ! $request->hasSession()) {
            return;
        }

        $q = self::queryDesdeInput($request);
        if ($q !== []) {
            $request->session()->put(self::SESSION_KEY, $q);

            return;
        }

        $request->session()->forget(self::SESSION_KEY);
    }

    public static function url(Request $request): string
    {
        $q = self::query($request);
        $from = (string) ($q['from'] ?? '');
        unset($q['from']);
        if (isset($q['buscar_nronota'])) {
            $q['nronota'] = $q['buscar_nronota'];
            unset($q['buscar_nronota']);
        }

        return match ($from) {
            self::FROM_OPORTUNIDADES => route('admin.oportunidades.para-cotizar.index', $q),
            self::FROM_ADJUDICADAS => route('admin.cotizaciones.adjudicadas.index', $q),
            self::FROM_LISTADO => route('admin.cotizaciones.index', $q),
            default => route('admin.cotizaciones.index'),
        };
    }

    public static function label(Request $request): string
    {
        return match ((string) (self::query($request)['from'] ?? '')) {
            self::FROM_OPORTUNIDADES => 'Oportunidades',
            self::FROM_ADJUDICADAS => 'Adjudicadas',
            default => 'Listado',
        };
    }

    /**
     * @return array<string, scalar>
     */
    private static function queryDesdeInput(Request $request): array
    {
        $from = trim((string) $request->input('from', ''));
        if (! in_array($from, [self::FROM_OPORTUNIDADES, self::FROM_LISTADO, self::FROM_ADJUDICADAS], true)) {
            return [];
        }

        $out = ['from' => $from];
        $claves = match ($from) {
            self::FROM_OPORTUNIDADES => self::CLAVES_OPORTUNIDADES,
            self::FROM_ADJUDICADAS => ['fechaentregadesde', 'fechaentregahasta', 'page', 'buscar_nronota', 'por_pagina'],
            default => ['fechadesde', 'fechahasta', 'cotizacion', 'estado_mp', 'orden_campo', 'orden_dir', 'page', 'buscar_nronota', 'por_pagina'],
        };

        foreach ($claves as $key) {
            $valor = $request->input($key);
            if ($valor === null || $valor === '') {
                continue;
            }
            $out[$key] = is_scalar($valor) ? $valor : '';
        }

        return self::soloClavesPermitidas($out);
    }

    /**
     * @param  array<string, mixed>  $raw
     * @return array<string, scalar>
     */
    private static function soloClavesPermitidas(array $raw): array
    {
        $from = trim((string) ($raw['from'] ?? ''));
        if (! in_array($from, [self::FROM_OPORTUNIDADES, self::FROM_LISTADO, self::FROM_ADJUDICADAS], true)) {
            return [];
        }

        $permitidas = array_merge(
            [
                'from', 'fechadesde', 'fechahasta', 'cotizacion', 'estado_mp',
                'orden_campo', 'orden_dir', 'page', 'buscar_nronota', 'por_pagina',
                'fechaentregadesde', 'fechaentregahasta',
            ],
            self::CLAVES_OPORTUNIDADES,
        );
        $out = [];
        foreach ($permitidas as $key) {
            if (! array_key_exists($key, $raw)) {
                continue;
            }
            $valor = $raw[$key];
            if ($valor === null || $valor === '' || ! is_scalar($valor)) {
                continue;
            }
            $out[$key] = $valor;
        }
        $out['from'] = $from;

        return $out;
    }
}
