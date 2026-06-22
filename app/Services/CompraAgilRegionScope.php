<?php

namespace App\Services;

/**
 * Alcance geográfico de consultas Compra Ágil (códigos región API Mercado Público).
 */
class CompraAgilRegionScope
{
    /** @var array<int, string> Códigos región API Mercado Público. */
    private const NOMBRES_REGION = [
        1 => 'Tarapacá',
        2 => 'Antofagasta',
        3 => 'Atacama',
        4 => 'Coquimbo',
        5 => 'Valparaíso',
        6 => "O'Higgins",
        7 => 'Maule',
        8 => 'Biobío',
        9 => 'Araucanía',
        10 => 'Los Lagos',
        11 => 'Aysén',
        12 => 'Magallanes',
        13 => 'Metropolitana',
        14 => 'Los Ríos',
        15 => 'Arica y Parinacota',
        16 => 'Ñuble',
    ];

    /** @return list<int> */
    public static function regionesIncluidas(): array
    {
        $regiones = config('cotiz.mercadopublico.regiones', []);

        return is_array($regiones)
            ? array_values(array_unique(array_map('intval', $regiones)))
            : [];
    }

    /**
     * @param  array<string, mixed>  $item  ítem crudo del listado o detalle API
     */
    public static function debeExcluirItem(array $item): bool
    {
        $institucion = is_array($item['institucion'] ?? null) ? $item['institucion'] : [];
        $region = isset($institucion['region']) ? (int) $institucion['region'] : null;

        return self::regionFueraDeAlcance($region);
    }

    /**
     * @param  array<string, mixed>  $resumen  ítem ya mapeado (listado enriquecido)
     */
    public static function debeExcluirResumen(array $resumen): bool
    {
        $region = isset($resumen['region']) ? (int) $resumen['region'] : null;

        return self::regionFueraDeAlcance($region);
    }

    public static function mensajeZonaExcluida(): string
    {
        return 'Esta Compra Ágil pertenece a una región fuera del área de operación configurada.';
    }

    /**
     * Regiones habilitadas con nombre para selectores UI.
     *
     * @return array<int, string>
     */
    public static function catalogoRegiones(): array
    {
        $catalogo = [];
        foreach (self::regionesIncluidas() as $codigo) {
            if (isset(self::NOMBRES_REGION[$codigo])) {
                $catalogo[$codigo] = self::NOMBRES_REGION[$codigo];
            }
        }
        ksort($catalogo);

        return $catalogo;
    }

    public static function nombreRegion(int $codigo): string
    {
        return self::NOMBRES_REGION[$codigo] ?? 'Región '.$codigo;
    }

    private static function regionFueraDeAlcance(?int $region): bool
    {
        if ($region === null) {
            return false;
        }

        $incluidas = self::regionesIncluidas();

        return $incluidas !== [] && ! in_array($region, $incluidas, true);
    }
}
