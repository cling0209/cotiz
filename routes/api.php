<?php

use App\Http\Controllers\Api\V1\AgileController;
use App\Http\Controllers\Api\V1\NotaApiController;
use App\Http\Controllers\Api\V1\NotaConsultaApiController;
use App\Http\Controllers\Api\V1\NotaEnvioApiController;
use App\Http\Controllers\Api\V1\OportunidadPalabraClaveApiController;
use App\Http\Controllers\Api\V1\UserApiController;
use Illuminate\Support\Facades\Route;

Route::prefix('v1')->group(function () {
    Route::get('/health', fn () => response()->json(['status' => 'ok']));

    Route::post('/agile', [AgileController::class, 'store'])
        ->middleware('agile.basic');

    Route::post('/nota', [NotaApiController::class, 'store'])
        ->middleware('nota.basic');

    Route::post('/nota-envio', [NotaEnvioApiController::class, 'store'])
        ->middleware('nota.basic');

    Route::post('/nota-consulta', [NotaConsultaApiController::class, 'store'])
        ->middleware('nota.basic');

    Route::post('/usuario', [UserApiController::class, 'store'])
        ->middleware('nota.basic');

    Route::post('/palabra-clave', [OportunidadPalabraClaveApiController::class, 'store'])
        ->middleware('nota.basic');
});

// Alias legacy
Route::post('/agile', [AgileController::class, 'store'])
    ->middleware('agile.basic');

Route::post('/nota', [NotaApiController::class, 'store'])
    ->middleware('nota.basic');

Route::post('/nota-envio', [NotaEnvioApiController::class, 'store'])
    ->middleware('nota.basic');

Route::post('/nota-consulta', [NotaConsultaApiController::class, 'store'])
    ->middleware('nota.basic');

Route::post('/usuario', [UserApiController::class, 'store'])
    ->middleware('nota.basic');

Route::post('/palabra-clave', [OportunidadPalabraClaveApiController::class, 'store'])
    ->middleware('nota.basic');
