<?php

require dirname(__DIR__).'/vendor/autoload.php';
$app = require dirname(__DIR__).'/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

echo 'APP_KEY: '.(config('app.key') ? 'set' : 'missing').PHP_EOL;
echo 'Products: '.App\Models\Product::count().PHP_EOL;

$request = Illuminate\Http\Request::create('/', 'GET');
$response = $app->handleRequest($request);
echo 'Home status: '.$response->getStatusCode().PHP_EOL;
if ($response->getStatusCode() !== 200) {
    exit(1);
}
echo 'OK: shop home rendered'.PHP_EOL;
