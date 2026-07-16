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

    /** Regiones fuera del área de operación (siempre, aunque estén en config). */
    private const REGIONES_SIEMPRE_EXCLUIDAS = [
        12, // Magallanes
    ];

    /**
     * Comunas excluidas (Isla de Pascua pertenece a Valparaíso en la API).
     *
     * @var list<string>
     */
    private const COMUNAS_EXCLUIDAS_CONTIENEN = [
        'isla de pascua',
        'rapa nui',
    ];

    /** @return list<int> */
    public static function regionesIncluidas(): array
    {
        $regiones = config('cotiz.mercadopublico.regiones', []);

        $incluidas = is_array($regiones)
            ? array_values(array_unique(array_map('intval', $regiones)))
            : [];

        return array_values(array_filter(
            $incluidas,
            fn (int $codigo) => ! in_array($codigo, self::REGIONES_SIEMPRE_EXCLUIDAS, true),
        ));
    }

    /**
     * @param  array<string, mixed>  $item  ítem crudo del listado o detalle API
     */
    public static function debeExcluirItem(array $item): bool
    {
        $institucion = is_array($item['institucion'] ?? null) ? $item['institucion'] : [];
        $region = isset($institucion['region']) ? (int) $institucion['region'] : null;
        $comuna = trim((string) ($institucion['comuna'] ?? $institucion['nombre_comuna'] ?? ''));

        if (self::comunaExcluida($comuna)) {
            return true;
        }

        return self::regionFueraDeAlcance($region);
    }

    /**
     * @param  array<string, mixed>  $resumen  ítem ya mapeado (listado enriquecido)
     */
    public static function debeExcluirResumen(array $resumen): bool
    {
        $region = isset($resumen['region']) ? (int) $resumen['region'] : null;
        $comuna = trim((string) ($resumen['comuna'] ?? ''));

        if (self::comunaExcluida($comuna)) {
            return true;
        }

        return self::regionFueraDeAlcance($region);
    }

    public static function mensajeZonaExcluida(): string
    {
        return 'Esta Compra Ágil pertenece a una zona fuera del área de operación (Magallanes o Isla de Pascua).';
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

    /**
     * Posición de la región en MERCADOPUBLICO_REGIONES (menor = mayor prioridad).
     * Regiones fuera de la lista quedan al final.
     */
    public static function indiceEnConfig(?int $region): int
    {
        if ($region === null) {
            return 999;
        }

        $pos = array_search($region, self::regionesIncluidas(), true);

        return $pos === false ? 999 : (int) $pos;
    }

    /**
     * Distancia relativa a la Región Metropolitana (menor = más cerca de Santiago).
     * Usado como desempate al ordenar oportunidades.
     */
    public static function distanciaASantiago(?int $region): int
    {
        if ($region === null) {
            return 99;
        }

        static $orden = [
            13 => 0,  // Metropolitana
            5 => 1,   // Valparaíso
            6 => 2,   // O'Higgins
            7 => 3,   // Maule
            16 => 4,  // Ñuble
            4 => 5,   // Coquimbo
            8 => 6,   // Biobío
            14 => 7,  // Los Ríos
            9 => 8,   // Araucanía
            3 => 9,   // Atacama
            10 => 10, // Los Lagos
            2 => 11,  // Antofagasta
            1 => 12,  // Tarapacá
            15 => 13, // Arica y Parinacota
            11 => 14, // Aysén
        ];

        return $orden[$region] ?? 50;
    }

    private static function regionFueraDeAlcance(?int $region): bool
    {
        if ($region === null) {
            return false;
        }

        if (in_array($region, self::REGIONES_SIEMPRE_EXCLUIDAS, true)) {
            return true;
        }

        $incluidas = self::regionesIncluidas();

        return $incluidas !== [] && ! in_array($region, $incluidas, true);
    }

    private static function comunaExcluida(string $comuna): bool
    {
        $norm = mb_strtolower(trim($comuna), 'UTF-8');
        if ($norm === '') {
            return false;
        }

        // Quitar tildes simples para matching robusto.
        $norm = strtr($norm, [
            'á' => 'a', 'é' => 'e', 'í' => 'i', 'ó' => 'o', 'ú' => 'u', 'ñ' => 'n',
        ]);

        foreach (self::COMUNAS_EXCLUIDAS_CONTIENEN as $patron) {
            if (str_contains($norm, $patron)) {
                return true;
            }
        }

        return false;
    }
}
