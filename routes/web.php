<?php

use App\Http\Controllers\Web\Admin\AgileRecepcionController;
use App\Http\Controllers\Web\Admin\AuthController;
use App\Http\Controllers\Web\Admin\AccountController;
use App\Http\Controllers\Web\Admin\CotizacionController;
use App\Http\Controllers\Web\Admin\CotizacionExportController;
use App\Http\Controllers\Web\Admin\CotizacionListadoController;
use App\Http\Controllers\Web\Admin\MaeprodController;
use App\Http\Controllers\Web\Admin\PasswordResetController;
use App\Http\Controllers\Web\Admin\UserController;
use Illuminate\Support\Facades\Route;

Route::redirect('/', '/admin/login');

Route::prefix('admin')->name('admin.')->group(function () {
    Route::get('login', [AuthController::class, 'showLogin'])->name('login');
    Route::post('login', [AuthController::class, 'login'])
        ->middleware('throttle:10,1')
        ->name('login.store');

    Route::get('password/forgot', [PasswordResetController::class, 'create'])->name('password.request');
    Route::post('password/forgot', [PasswordResetController::class, 'store'])
        ->middleware('throttle:6,1')
        ->name('password.email');
    Route::get('password/reset', [PasswordResetController::class, 'edit'])->name('password.reset');
    Route::get('password/reset/{token}', [PasswordResetController::class, 'edit'])->name('password.reset.link');
    Route::post('password/reset', [PasswordResetController::class, 'update'])
        ->middleware('throttle:6,1')
        ->name('password.update');

    Route::middleware('auth')->group(function () {
        Route::post('logout', [AuthController::class, 'logout'])->name('logout');

        Route::get('cuenta/contrasena', [AccountController::class, 'editPassword'])->name('account.password');
        Route::put('cuenta/contrasena', [AccountController::class, 'updatePassword'])->name('account.password.update');

        Route::redirect('/', '/admin/cotizaciones');

        Route::get('agile-recepcion', [AgileRecepcionController::class, 'index'])->name('agile.index');
        Route::get('agile-recepcion/productos/buscar', [AgileRecepcionController::class, 'buscarProductos'])->name('agile.productos.buscar');
        Route::get('agile-recepcion/{nronota}', [AgileRecepcionController::class, 'show'])->name('agile.show')->whereNumber('nronota');
        Route::post('agile-recepcion/{nronota}/aprobar', [AgileRecepcionController::class, 'aprobar'])->name('agile.aprobar')->whereNumber('nronota');
        Route::post('agile-recepcion/{nronota}/factor', [AgileRecepcionController::class, 'actualizarFactor'])->name('agile.factor')->whereNumber('nronota');
        Route::post('agile-recepcion/{nronota}/lineas/precio', [AgileRecepcionController::class, 'actualizarPrecio'])->name('agile.lineas.precio')->whereNumber('nronota');
        Route::delete('agile-recepcion/{nronota}', [AgileRecepcionController::class, 'destroy'])->name('agile.destroy')->whereNumber('nronota');

        Route::get('cotizaciones', [CotizacionListadoController::class, 'index'])->name('cotizaciones.index');
        Route::post('cotizaciones/{nronota}/enviar', [CotizacionListadoController::class, 'enviar'])->name('cotizaciones.enviar')->whereNumber('nronota');
        Route::post('cotizaciones/{nronota}/aceptar', [CotizacionListadoController::class, 'aceptar'])->name('cotizaciones.aceptar')->whereNumber('nronota');
        Route::post('cotizaciones/{nronota}/no-aceptar', [CotizacionListadoController::class, 'noAceptar'])->name('cotizaciones.no-aceptar')->whereNumber('nronota');
        Route::get('cotizaciones/{nronota}/asignar', [CotizacionListadoController::class, 'asignarForm'])->name('cotizaciones.asignar')->whereNumber('nronota');
        Route::post('cotizaciones/{nronota}/asignar', [CotizacionListadoController::class, 'asignar'])->name('cotizaciones.asignar.store')->whereNumber('nronota');
        Route::get('cotizaciones/export/sin-codigo-softland', [CotizacionListadoController::class, 'exportSinCodigoSoftland'])->name('cotizaciones.export.sin-codigo-softland');
        Route::get('cotizaciones/export/aceptadas', [CotizacionListadoController::class, 'exportAceptadas'])->name('cotizaciones.export.aceptadas');
        Route::match(['get', 'post'], 'cotizaciones/nueva', [CotizacionController::class, 'create'])->name('cotizaciones.create');
        Route::get('cotizaciones/retomar', [CotizacionController::class, 'retomar'])->name('cotizaciones.retomar');
        Route::get('productos/buscar', [CotizacionController::class, 'buscarProductos'])->name('productos.buscar');
        Route::get('cotizaciones/{nronota}', [CotizacionController::class, 'edit'])->name('cotizaciones.edit')->whereNumber('nronota');
        Route::put('cotizaciones/{nronota}', [CotizacionController::class, 'update'])->name('cotizaciones.update')->whereNumber('nronota');
        Route::post('cotizaciones/{nronota}/importar-compra-agil/preview', [CotizacionController::class, 'importarCompraAgilPreview'])->name('cotizaciones.importar-compra-agil.preview')->whereNumber('nronota');
        Route::post('cotizaciones/{nronota}/importar-compra-agil', [CotizacionController::class, 'importarCompraAgil'])->name('cotizaciones.importar-compra-agil')->whereNumber('nronota');
        Route::post('cotizaciones/{nronota}/lineas', [CotizacionController::class, 'agregarLinea'])->name('cotizaciones.lineas.store')->whereNumber('nronota');
        Route::patch('cotizaciones/{nronota}/lineas/orden', [CotizacionController::class, 'cambiarOrdenLinea'])->name('cotizaciones.lineas.orden')->whereNumber('nronota');
        Route::delete('cotizaciones/{nronota}/lineas', [CotizacionController::class, 'eliminarLinea'])->name('cotizaciones.lineas.destroy')->whereNumber('nronota');

        Route::get('cotizaciones/{nronota}/export/pdf', [CotizacionExportController::class, 'pdf'])->name('cotizaciones.export.pdf')->whereNumber('nronota');
        Route::get('cotizaciones/{nronota}/export/archivo', [CotizacionExportController::class, 'archivo'])->name('cotizaciones.export.archivo')->whereNumber('nronota');
        Route::get('cotizaciones/{nronota}/export/excel', [CotizacionExportController::class, 'excel'])->name('cotizaciones.export.excel')->whereNumber('nronota');
        Route::get('cotizaciones/{nronota}/export/guia', [CotizacionExportController::class, 'guia'])->name('cotizaciones.export.guia')->whereNumber('nronota');
        Route::get('cotizaciones/{nronota}/export/guia-ingreso', [CotizacionExportController::class, 'guiaIngreso'])->name('cotizaciones.export.guia-ingreso')->whereNumber('nronota');

        Route::middleware('superadmin')->group(function () {
            Route::get('productos/carga-masiva', [MaeprodController::class, 'importForm'])->name('productos.import');
            Route::get('productos/carga-masiva/estado', [MaeprodController::class, 'importStatus'])->name('productos.import.status');
            Route::get('productos/carga-masiva/resultado/{run}', [MaeprodController::class, 'importResult'])->name('productos.import.resultado')->whereNumber('run');
            Route::get('productos/carga-masiva/errores/{run}', [MaeprodController::class, 'importErrors'])->name('productos.import.errores')->whereNumber('run');
            Route::get('productos/carga-masiva/errores/{run}/exportar', [MaeprodController::class, 'exportImportErrors'])->name('productos.import.errores.exportar')->whereNumber('run');
            Route::get('productos/carga-masiva/plantilla', [MaeprodController::class, 'downloadImportTemplate'])->name('productos.import.template');
            Route::get('productos/carga-masiva/plantilla-excel', [MaeprodController::class, 'downloadImportTemplateExcel'])->name('productos.import.template.excel');
            Route::post('productos/carga-masiva/chunk', [MaeprodController::class, 'storeImportChunk'])->name('productos.import.chunk');
            Route::post('productos/carga-masiva/inicializar', [MaeprodController::class, 'initializeCustomImport'])->name('productos.import.initialize');
            Route::post('productos/carga-masiva/vista-previa', [MaeprodController::class, 'previewImportMapping'])->name('productos.import.preview');
            Route::post('productos/carga-masiva/preparar', [MaeprodController::class, 'prepareCustomImport'])->name('productos.import.prepare');
            Route::post('productos/carga-masiva/procesar', [MaeprodController::class, 'processImportBatch'])->name('productos.import.process');
            Route::get('productos/exportar', [MaeprodController::class, 'exportCsv'])->name('productos.export');

            Route::get('productos', [MaeprodController::class, 'index'])->name('productos.index');
            Route::get('productos/nuevo', [MaeprodController::class, 'create'])->name('productos.create');
            Route::post('productos', [MaeprodController::class, 'store'])->name('productos.store');
            Route::get('productos/{prod_item}', [MaeprodController::class, 'edit'])->name('productos.edit')->where('prod_item', '[^/]+');
            Route::put('productos/{prod_item}', [MaeprodController::class, 'update'])->name('productos.update')->where('prod_item', '[^/]+');

            Route::get('usuarios', [UserController::class, 'index'])->name('users.index');
            Route::get('usuarios/nuevo', [UserController::class, 'create'])->name('users.create');
            Route::post('usuarios', [UserController::class, 'store'])->name('users.store');
            Route::get('usuarios/{usuario}/editar', [UserController::class, 'edit'])->name('users.edit');
            Route::put('usuarios/{usuario}', [UserController::class, 'update'])->name('users.update');
            Route::delete('usuarios/{usuario}', [UserController::class, 'destroy'])->name('users.destroy');
        });
    });
});
