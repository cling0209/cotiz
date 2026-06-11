<?php

/**
 * @deprecated Usar build_chile_regions_from_cut.php (códigos CUT oficiales).
 */

$sourcePath = __DIR__.'/resumen_source.json';
if (! file_exists($sourcePath)) {
    fwrite(STDERR, "Missing resumen_source.json — ejecuta: php database/data/build_chile_regions_from_cut.php\n");
    exit(1);
}

$source = json_decode(file_get_contents($sourcePath), true);

$order = [
    'Región de Arica y Parinacota',
    'Región de Tarapacá',
    'Región de Antofagasta',
    'Región de Atacama',
    'Región de Coquimbo',
    'Región de Valparaíso',
    'Región Metropolitana de Santiago',
    "Región de O'Higgins",
    'Región del Maule',
    'Región de Ñuble',
    'Región del Biobío',
    'Región de La Araucanía',
    'Región de Los Ríos',
    'Región de Los Lagos',
    'Región de Aysén',
    'Región de Magallanes',
];

$nameMap = [
    'Región de Aysén' => 'Aysén del General Carlos Ibáñez del Campo',
    'Región de Magallanes' => 'Magallanes y la Antártica Chilena',
];

$fixComuna = static function (string $name): string {
    return match ($name) {
        'Parquenco' => 'Perquenco',
        'Coihaique' => 'Coyhaique',
        'Natales' => 'Puerto Natales',
        default => $name,
    };
};

$collator = class_exists(Collator::class) ? new Collator('es_CL') : null;

$out = [];

foreach ($order as $regionKey) {
    if (! isset($source[$regionKey])) {
        fwrite(STDERR, "Missing region: {$regionKey}\n");
        continue;
    }

    $comunas = [];
    foreach ($source[$regionKey]['provincias'] as $provincia) {
        foreach ($provincia['comunas'] as $comuna) {
            $comunas[] = $fixComuna($comuna);
        }
    }

    $comunas = array_values(array_unique($comunas));

    if ($collator) {
        usort($comunas, static fn (string $a, string $b): int => $collator->compare($a, $b));
    } else {
        sort($comunas, SORT_LOCALE_STRING);
    }

    $out[] = [
        'region' => $nameMap[$regionKey] ?? $regionKey,
        'comunas' => $comunas,
    ];
}

$json = json_encode($out, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
file_put_contents(__DIR__.'/chile_regions.json', $json.PHP_EOL);

$totalComunas = array_sum(array_map(static fn (array $r): int => count($r['comunas']), $out));
echo count($out).' regions, '.$totalComunas.' comunas'.PHP_EOL;
