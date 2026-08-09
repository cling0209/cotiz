<?php
require dirname(__DIR__).'/vendor/autoload.php';
$app = require dirname(__DIR__).'/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use Illuminate\Support\Facades\Http;

$hoja = (int) ($argv[1] ?? 4);
$pdf = $argv[2] ?? '/pdf/doc.pdf';
$url = rtrim(getenv('COTIZ_PADDLEOCR_URL') ?: 'http://paddleocr:8080', '/');

$bytes = file_get_contents($pdf);
$r = Http::timeout(600)
    ->attach('pdf', $bytes, basename($pdf))
    ->post($url.'/extract-tabla', ['first_page' => $hoja, 'last_page' => $hoja]);

echo "HTTP {$r->status()}\n";
echo $r->body();
