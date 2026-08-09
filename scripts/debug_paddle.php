<?php
require dirname(__DIR__).'/vendor/autoload.php';
$app = require dirname(__DIR__).'/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

echo 'COTIZ_PADDLEOCR_URL env: '.(getenv('COTIZ_PADDLEOCR_URL') ?: '(empty)')."\n";
echo 'config url: '.config('cotiz.paddleocr.url')."\n";
echo 'config enabled: '.(config('cotiz.paddleocr.enabled') ? 'yes' : 'no')."\n";

try {
    $r = Illuminate\Support\Facades\Http::timeout(5)->get('http://localhost:8010/health');
    echo 'health status: '.$r->status().' body: '.$r->body()."\n";
} catch (Throwable $e) {
    echo 'health error: '.$e->getMessage()."\n";
}

$paddle = new App\Services\PdfPaddleOcrService;
echo 'estaDisponible: '.($paddle->estaDisponible() ? 'yes' : 'no')."\n";
