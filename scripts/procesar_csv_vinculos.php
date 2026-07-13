<?php

$root = dirname(__DIR__);
require $root.'/vendor/autoload.php';
$app = require $root.'/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Models\AgileMaeprod;
use App\Models\Maeprod;
use App\Services\AgileVinculoAuditoriaService;
use Illuminate\Support\Facades\DB;

$csvIn = $argv[1] ?? 'C:\\Users\\csoto\\Downloads\\datos.csv';
$baseName = pathinfo($csvIn, PATHINFO_FILENAME);
$dir = dirname($csvIn);
$csvOut = $argv[2] ?? ($dir.DIRECTORY_SEPARATOR.$baseName.'_eliminar_vinculos.csv');
$csvOk = $argv[3] ?? ($dir.DIRECTORY_SEPARATOR.$baseName.'_vinculos_ok.csv');

$auditoria = app(AgileVinculoAuditoriaService::class);
$minimo = $auditoria->scoreMinimoDefault();

$toUtf8 = static function (?string $value): string {
    $value = (string) $value;
    if ($value === '') {
        return '';
    }
    if (! mb_check_encoding($value, 'UTF-8')) {
        $converted = @iconv('Windows-1252', 'UTF-8//IGNORE', $value);
        if ($converted === false) {
            $converted = @mb_convert_encoding($value, 'UTF-8', 'Windows-1252,ISO-8859-1,UTF-8');
        }
        $value = $converted !== false ? $converted : preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F]/', '', $value);
    }
    // bytes inválidos residuales
    $clean = @iconv('UTF-8', 'UTF-8//IGNORE', $value);

    return trim($clean !== false ? $clean : $value);
};

$raw = file_get_contents($csvIn);
if ($raw === false) {
    fwrite(STDERR, "No se pudo leer $csvIn\n");
    exit(1);
}
if (! mb_check_encoding($raw, 'UTF-8')) {
    $converted = @iconv('Windows-1252', 'UTF-8//IGNORE', $raw);
    $raw = $converted !== false ? $converted : $raw;
}
$raw = preg_replace('/^\xEF\xBB\xBF/', '', $raw) ?? $raw;

$tmp = sys_get_temp_dir().DIRECTORY_SEPARATOR.'datos_vinculos_utf8.csv';
file_put_contents($tmp, $raw);

$fh = fopen($tmp, 'rb');
if ($fh === false) {
    fwrite(STDERR, "No se pudo abrir temporal\n");
    exit(1);
}

$header = fgetcsv($fh, 0, ';');
if ($header === false) {
    fwrite(STDERR, "CSV vacío\n");
    exit(1);
}
$header = array_map($toUtf8, $header);
$map = array_flip($header);

foreach (['prod_item_agile', 'prod_descripcion_agile', 'prod_item'] as $col) {
    if (! isset($map[$col])) {
        fwrite(STDERR, "Falta columna: $col\n");
        exit(1);
    }
}

$rows = [];
$codigos = [];
while (($data = fgetcsv($fh, 0, ';')) !== false) {
    if (! is_array($data) || count($data) < 3) {
        continue;
    }
    $data = array_map($toUtf8, $data);
    $prodItem = $toUtf8($data[$map['prod_item']] ?? '');
    if ($prodItem === '' || $prodItem === '0') {
        continue;
    }
    $row = [
        'prod_item_agile' => $toUtf8($data[$map['prod_item_agile']] ?? ''),
        'prod_descripcion_agile' => $toUtf8($data[$map['prod_descripcion_agile']] ?? ''),
        'prod_item' => $prodItem,
        'descripcion_norm_hash' => isset($map['descripcion_norm_hash'])
            ? $toUtf8($data[$map['descripcion_norm_hash']] ?? '')
            : '',
        'prod_codigo_categoria_mp' => isset($map['prod_codigo_categoria_mp'])
            ? $toUtf8($data[$map['prod_codigo_categoria_mp']] ?? '')
            : '',
    ];
    $rows[] = $row;
    $codigos[$prodItem] = true;
}
fclose($fh);
@unlink($tmp);

echo 'Vinculadas en CSV: '.count($rows).PHP_EOL;
echo 'Códigos únicos: '.count($codigos).PHP_EOL;
echo 'Score mínimo: '.$minimo.PHP_EOL;

// Cargar maestros por lotes (evita query enorme + encoding raro en binding)
$maestros = collect();
foreach (array_chunk(array_keys($codigos), 400) as $chunk) {
    $chunk = array_values(array_filter(array_map($toUtf8, $chunk)));
    if ($chunk === []) {
        continue;
    }
    try {
        $found = Maeprod::query()
            ->whereIn('prod_item', $chunk)
            ->get(['prod_item', 'prod_nombre']);
        foreach ($found as $m) {
            $maestros->put((string) $m->prod_item, $m);
        }
    } catch (Throwable $e) {
        // Fallback: uno a uno si algún código rompe el lote
        foreach ($chunk as $code) {
            try {
                $m = Maeprod::query()->where('prod_item', $code)->first(['prod_item', 'prod_nombre']);
                if ($m) {
                    $maestros->put((string) $m->prod_item, $m);
                }
            } catch (Throwable $e2) {
                echo 'Skip código inválido: '.$code.PHP_EOL;
            }
        }
    }
}

echo 'Maestros encontrados: '.$maestros->count().PHP_EOL;

$malas = [];
$ok = [];
$porMotivo = [];

foreach ($rows as $r) {
    $model = new AgileMaeprod([
        'prod_item_agile' => $r['prod_item_agile'],
        'prod_descripcion_agile' => $r['prod_descripcion_agile'],
        'prod_item' => $r['prod_item'],
    ]);
    $eval = $auditoria->evaluarFila($model, $maestros->get($r['prod_item']), $minimo);
    $out = array_merge($r, [
        'prod_nombre' => $eval['prod_nombre'],
        'score' => $eval['score'],
        'estado' => $eval['estado'],
        'motivo' => $eval['motivo'],
    ]);
    if ($eval['estado'] === 'mala') {
        $malas[] = $out;
        $porMotivo[$eval['motivo']] = ($porMotivo[$eval['motivo']] ?? 0) + 1;
    } else {
        $ok[] = $out;
    }
}

$write = static function (string $path, array $filas): void {
    $fh = fopen($path, 'wb');
    fwrite($fh, "\xEF\xBB\xBF");
    fputcsv($fh, [
        'prod_item_agile',
        'prod_descripcion_agile',
        'prod_item',
        'prod_nombre',
        'score',
        'motivo',
        'descripcion_norm_hash',
        'prod_codigo_categoria_mp',
    ], ';');
    foreach ($filas as $f) {
        fputcsv($fh, [
            $f['prod_item_agile'],
            $f['prod_descripcion_agile'],
            $f['prod_item'],
            $f['prod_nombre'],
            $f['score'],
            $f['motivo'],
            $f['descripcion_norm_hash'],
            $f['prod_codigo_categoria_mp'],
        ], ';');
    }
    fclose($fh);
};

$write($csvOut, $malas);
$write($csvOk, $ok);

echo 'OK: '.count($ok).PHP_EOL;
echo 'MALA (eliminar): '.count($malas).PHP_EOL;
foreach ($porMotivo as $m => $c) {
    echo "  $m: $c".PHP_EOL;
}
echo "CSV eliminar: $csvOut".PHP_EOL;
echo "CSV ok: $csvOk".PHP_EOL;
