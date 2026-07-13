<?php

$root = dirname(__DIR__);
require $root.'/vendor/autoload.php';
$app = require $root.'/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Models\AgileMaeprod;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

echo 'cols: '.implode(', ', Schema::getColumnListing('agilemaeprod')).PHP_EOL;
echo 'total: '.AgileMaeprod::query()->count().PHP_EOL;
echo 'con prod_item: '.AgileMaeprod::query()
    ->whereNotNull('prod_item')
    ->where('prod_item', '!=', '')
    ->where('prod_item', '!=', '0')
    ->count().PHP_EOL;

$sample = AgileMaeprod::query()
    ->whereNotNull('prod_item')
    ->where('prod_item', '!=', '')
    ->limit(3)
    ->get(['prod_item_agile', 'prod_descripcion_agile', 'prod_item']);
foreach ($sample as $r) {
    echo json_encode($r->toArray(), JSON_UNESCAPED_UNICODE).PHP_EOL;
}
