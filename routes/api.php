<?php

use App\Http\Controllers\Api\V1\AgileController;
use Illuminate\Support\Facades\Route;

Route::prefix('v1')->group(function () {
    Route::get('/health', fn () => response()->json(['status' => 'ok']));

    Route::post('/agile', [AgileController::class, 'store'])
        ->middleware('agile.basic');
});

// Alias legacy apiagile.php
Route::post('/agile', [AgileController::class, 'store'])
    ->middleware('agile.basic');
