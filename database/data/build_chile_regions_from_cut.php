<?php

/**
 * Regenera chile_regions.json con códigos CUT oficiales desde cut_comuna.csv.
 *
 * Uso: php database/data/build_chile_regions_from_cut.php
 */

$cutPath = __DIR__.'/cut_comuna.csv';

if (! file_exists($cutPath)) {
    fwrite(STDERR, "Missing cut_comuna.csv\n");
    exit(1);
}

$regionNames = [
    '15' => 'Región de Arica y Parinacota',
    '01' => 'Región de Tarapacá',
    '02' => 'Región de Antofagasta',
    '03' => 'Región de Atacama',
    '04' => 'Región de Coquimbo',
    '05' => 'Región de Valparaíso',
    '13' => 'Región Metropolitana de Santiago',
    '06' => "Región de O'Higgins",
    '07' => 'Región del Maule',
    '16' => 'Región de Ñuble',
    '08' => 'Región del Biobío',
    '09' => 'Región de La Araucanía',
    '14' => 'Región de Los Ríos',
    '10' => 'Región de Los Lagos',
    '11' => 'Aysén del General Carlos Ibáñez del Campo',
    '12' => 'Magallanes y la Antártica Chilena',
];

$comunaAliases = [
    'Paiguano' => 'Paihuano',
    'Calera' => 'La Calera',
    'Llaillay' => 'Llay-Llay',
    'Natales' => 'Puerto Natales',
];

$normalizeKey = static function (string $name): string {
    $name = mb_strtolower(trim($name), 'UTF-8');
    $name = str_replace(['á', 'é', 'í', 'ó', 'ú', 'ñ', 'ü'], ['a', 'e', 'i', 'o', 'u', 'n', 'u'], $name);

    return preg_replace('/[^a-z0-9]+/', '', $name) ?? '';
};

/** @var array<string, array{codigo_region: string, region: string, comunas: list<array{codigo: string, nombre: string}>}> $byRegion */
$byRegion = [];

$handle = fopen($cutPath, 'r');

if ($handle === false) {
    fwrite(STDERR, "Cannot read cut_comuna.csv\n");
    exit(1);
}

$header = fgetcsv($handle, 0, ';', '"', '\\');

while (($row = fgetcsv($handle, 0, ';', '"', '\\')) !== false) {
    if (count($row) < 7) {
        continue;
    }

    $codigoRegion = str_pad(trim($row[0]), 2, '0', STR_PAD_LEFT);
    $codigoComuna = str_pad(trim($row[5]), 5, '0', STR_PAD_LEFT);
    $nombreCut = trim($row[6]);
    $nombre = $comunaAliases[$nombreCut] ?? $nombreCut;

    if (! isset($regionNames[$codigoRegion])) {
        continue;
    }

    if (! isset($byRegion[$codigoRegion])) {
        $byRegion[$codigoRegion] = [
            'codigo_region' => $codigoRegion,
            'region' => $regionNames[$codigoRegion],
            'comunas' => [],
        ];
    }

    $byRegion[$codigoRegion]['comunas'][] = [
        'codigo' => $codigoComuna,
        'nombre' => $nombre,
    ];
}

fclose($handle);

$collator = class_exists(Collator::class) ? new Collator('es_CL') : null;
$out = [];

foreach ($regionNames as $codigoRegion => $regionLabel) {
    if (! isset($byRegion[$codigoRegion])) {
        fwrite(STDERR, "Missing CUT data for region {$codigoRegion}\n");
        continue;
    }

    $comunas = $byRegion[$codigoRegion]['comunas'];

    usort($comunas, static function (array $a, array $b) use ($collator): int {
        if ($collator) {
            return $collator->compare($a['nombre'], $b['nombre']);
        }

        return strcasecmp($a['nombre'], $b['nombre']);
    });

    $out[] = [
        'codigo_region' => (string) $codigoRegion,
        'region' => $regionLabel,
        'comunas' => $comunas,
    ];
}

$json = json_encode($out, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
file_put_contents(__DIR__.'/chile_regions.json', $json.PHP_EOL);

$totalComunas = array_sum(array_map(static fn (array $r): int => count($r['comunas']), $out));
echo count($out).' regions, '.$totalComunas.' comunas (CUT)'.PHP_EOL;
