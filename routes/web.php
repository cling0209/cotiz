<?php

use App\Http\Controllers\Web\Admin\AuthController;
use App\Http\Controllers\Web\Admin\CotizacionController;
use App\Http\Controllers\Web\Admin\CotizacionListadoController;
use Illuminate\Support\Facades\Route;

Route::redirect('/', '/admin/login');

Route::prefix('admin')->name('admin.')->group(function () {
    Route::get('login', [AuthController::class, 'showLogin'])->name('login');
    Route::post('login', [AuthController::class, 'login'])
        ->middleware('throttle:10,1')
        ->name('login.store');

    Route::middleware('auth')->group(function () {
        Route::post('logout', [AuthController::class, 'logout'])->name('logout');

        Route::redirect('/', '/admin/cotizaciones');

        Route::get('cotizaciones', [CotizacionListadoController::class, 'index'])->name('cotizaciones.index');
        Route::post('cotizaciones/nueva', [CotizacionController::class, 'create'])->name('cotizaciones.create');
        Route::get('cotizaciones/retomar', [CotizacionController::class, 'retomar'])->name('cotizaciones.retomar');
        Route::get('cotizaciones/{nronota}', [CotizacionController::class, 'edit'])->name('cotizaciones.edit');
        Route::put('cotizaciones/{nronota}', [CotizacionController::class, 'update'])->name('cotizaciones.update');
        Route::post('cotizaciones/{nronota}/lineas', [CotizacionController::class, 'agregarLinea'])->name('cotizaciones.lineas.store');
        Route::delete('cotizaciones/{nronota}/lineas', [CotizacionController::class, 'eliminarLinea'])->name('cotizaciones.lineas.destroy');
        Route::get('productos/buscar', [CotizacionController::class, 'buscarProductos'])->name('productos.buscar');
    });
});
