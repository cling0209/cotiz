<?php

$root = dirname(__DIR__);
require $root.'/vendor/autoload.php';
$app = require $root.'/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Models\AgileMaeprod;
use Illuminate\Support\Facades\DB;

$csvIn = $argv[1] ?? 'C:\\Users\\csoto\\Downloads\\datos2_eliminar_vinculos.csv';

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

$normKey = static function (string $desc, string $prod) use ($toUtf8): string {
    $desc = mb_strtoupper($toUtf8($desc), 'UTF-8');
    $desc = preg_replace('/\s+/u', ' ', $desc) ?? $desc;

    return $desc.'|'.$toUtf8($prod);
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
$tmp = sys_get_temp_dir().DIRECTORY_SEPARATOR.'eliminar_vinculos_mem.csv';
file_put_contents($tmp, $raw);

$fh = fopen($tmp, 'rb');
$header = fgetcsv($fh, 0, ';');
$header = array_map($toUtf8, $header ?: []);
$map = array_flip($header);

$csvIds = [];
$csvPares = [];
$filas = 0;
while (($data = fgetcsv($fh, 0, ';')) !== false) {
    if (! is_array($data) || count($data) < 3) {
        continue;
    }
    $data = array_map($toUtf8, $data);
    $filas++;
    $id = (string) ($data[$map['prod_item_agile']] ?? '');
    $desc = (string) ($data[$map['prod_descripcion_agile']] ?? '');
    $prod = (string) ($data[$map['prod_item']] ?? '');
    if ($id !== '') {
        $csvIds[$id] = true;
    }
    if ($desc !== '' && $prod !== '' && $prod !== '0') {
        $csvPares[$normKey($desc, $prod)] = true;
    }
}
fclose($fh);
@unlink($tmp);

echo "Filas CSV: $filas\n";
echo 'IDs CSV: '.count($csvIds)."\n";
echo 'Pares desc+código CSV: '.count($csvPares)."\n";

$pks = [];
$matchId = 0;
$matchPar = 0;

$vinculados = AgileMaeprod::query()
    ->whereNotNull('prod_item')
    ->where('prod_item', '!=', '')
    ->where('prod_item', '!=', '0')
    ->get(['prod_item_agile', 'prod_descripcion_agile', 'prod_item']);

foreach ($vinculados as $row) {
    $pk = (string) $row->prod_item_agile;
    $hit = false;
    if (isset($csvIds[$pk])) {
        $hit = true;
        $matchId++;
    }
    $key = $normKey((string) $row->prod_descripcion_agile, (string) $row->prod_item);
    if (isset($csvPares[$key])) {
        $hit = true;
        $matchPar++;
    }
    if ($hit) {
        $pks[$pk] = true;
    }
}

$pkList = array_keys($pks);
echo "Match por ID: $matchId\n";
echo "Match por desc+código: $matchPar\n";
echo 'PK únicas a eliminar: '.count($pkList)."\n";

if ($pkList === []) {
    echo "Nada que borrar.\n";
    exit(0);
}

$borradas = 0;
DB::transaction(function () use ($pkList, &$borradas): void {
    foreach (array_chunk($pkList, 500) as $chunk) {
        $chunk = array_map('strval', $chunk);
        $borradas += AgileMaeprod::query()
            ->whereIn(DB::raw('prod_item_agile::text'), $chunk)
            ->delete();
    }
});

echo "Eliminadas: $borradas\n";
echo 'Vinculadas restantes: '.AgileMaeprod::query()
    ->whereNotNull('prod_item')
    ->where('prod_item', '!=', '')
    ->where('prod_item', '!=', '0')
    ->count().PHP_EOL;
