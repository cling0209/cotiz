<?php

$root = dirname(__DIR__);
require $root.'/vendor/autoload.php';
$app = require $root.'/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Models\AgileMaeprod;
use Illuminate\Support\Facades\DB;

$csvIn = $argv[1] ?? 'C:\\Users\\csoto\\Downloads\\datos_eliminar_vinculos.csv';

$toUtf8 = static function (?string $value): string {
    $value = (string) $value;
    if ($value === '') {
        return '';
    }
    if (! mb_check_encoding($value, 'UTF-8')) {
        $converted = @iconv('Windows-1252', 'UTF-8//IGNORE', $value);
        $value = $converted !== false ? $converted : $value;
    }
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

$tmp = sys_get_temp_dir().DIRECTORY_SEPARATOR.'eliminar_vinculos_utf8.csv';
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
if (! isset($map['prod_item_agile'])) {
    fwrite(STDERR, "Falta columna prod_item_agile\n");
    exit(1);
}

$ids = [];
while (($data = fgetcsv($fh, 0, ';')) !== false) {
    if (! is_array($data) || $data === []) {
        continue;
    }
    $id = $toUtf8($data[$map['prod_item_agile']] ?? '');
    if ($id !== '') {
        // Importante: no usar el id como key de array (PHP convierte "39339377" → int).
        $ids[] = (string) $id;
    }
}
fclose($fh);
@unlink($tmp);

$ids = array_values(array_unique($ids));
echo 'IDs en CSV: '.count($ids).PHP_EOL;

$existentes = 0;
foreach (array_chunk($ids, 500) as $chunk) {
    $chunk = array_map('strval', $chunk);
    $existentes += AgileMaeprod::query()
        ->whereIn(DB::raw('prod_item_agile::text'), $chunk)
        ->count();
}
echo 'Existen en BD: '.$existentes.PHP_EOL;

if ($existentes === 0) {
    echo "Nada que borrar.\n";
    exit(0);
}

$borradas = 0;
DB::transaction(function () use ($ids, &$borradas): void {
    foreach (array_chunk($ids, 500) as $chunk) {
        $chunk = array_map('strval', $chunk);
        $borradas += AgileMaeprod::query()
            ->whereIn(DB::raw('prod_item_agile::text'), $chunk)
            ->delete();
    }
});

echo "Eliminadas: $borradas".PHP_EOL;

$restantes = 0;
foreach (array_chunk($ids, 500) as $chunk) {
    $chunk = array_map('strval', $chunk);
    $restantes += AgileMaeprod::query()
        ->whereIn(DB::raw('prod_item_agile::text'), $chunk)
        ->count();
}
echo "Restantes de esa lista: $restantes".PHP_EOL;
