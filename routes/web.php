<?php

use App\Http\Controllers\Web\Admin\AdjudicadaListadoController;
use App\Http\Controllers\Web\Admin\AgileRecepcionController;
use App\Http\Controllers\Web\Admin\AuthController;
use App\Http\Controllers\Web\Admin\AccountController;
use App\Http\Controllers\Web\Admin\CompraAgilAnalisisController;
use App\Http\Controllers\Web\Admin\CompraAgilResultadosController;
use App\Http\Controllers\Web\Admin\CompraAgilBusquedaController;
use App\Http\Controllers\Web\Admin\CorreosChileTarifaController;
use App\Http\Controllers\Web\Admin\CotizacionCargaArchivoController;
use App\Http\Controllers\Web\Admin\CotizacionController;
use App\Http\Controllers\Web\Admin\CotizacionEnvioDexController;
use App\Http\Controllers\Web\Admin\CotizacionExportController;
use App\Http\Controllers\Web\Admin\CotizacionListadoController;
use App\Http\Controllers\Web\Admin\MaeprodController;
use App\Http\Controllers\Web\Admin\OportunidadPalabraClaveController;
use App\Http\Controllers\Web\Admin\OportunidadParaCotizarController;
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
        Route::get('cotizaciones/carga-archivo', [CotizacionCargaArchivoController::class, 'index'])->name('cotizaciones.carga-archivo.index');
        Route::post('cotizaciones/carga-archivo/previsualizar', [CotizacionCargaArchivoController::class, 'previsualizar'])->name('cotizaciones.carga-archivo.previsualizar');
        Route::post('cotizaciones/carga-archivo/confirmar', [CotizacionCargaArchivoController::class, 'confirmar'])->name('cotizaciones.carga-archivo.confirmar');
        Route::get('cotizaciones/carga-archivo/plantilla', [CotizacionCargaArchivoController::class, 'plantilla'])->name('cotizaciones.carga-archivo.plantilla');
        Route::get('productos/buscar', [CotizacionController::class, 'buscarProductos'])->name('productos.buscar');
        Route::get('productos', [MaeprodController::class, 'index'])->name('productos.index');
        Route::get('productos/nuevo', [MaeprodController::class, 'create'])->name('productos.create');
        Route::post('productos', [MaeprodController::class, 'store'])->name('productos.store');
        Route::get('productos/{prod_item}/imagen', [MaeprodController::class, 'editImagen'])->name('productos.imagen.edit')->where('prod_item', '[^/]+');
        Route::put('productos/{prod_item}/imagen', [MaeprodController::class, 'updateImagen'])->name('productos.imagen.update')->where('prod_item', '[^/]+');

        Route::get('cotizaciones/{nronota}', [CotizacionController::class, 'edit'])->name('cotizaciones.edit')->whereNumber('nronota');
        Route::match(['put', 'post'], 'cotizaciones/{nronota}', [CotizacionController::class, 'update'])->name('cotizaciones.update')->whereNumber('nronota');
        Route::post('cotizaciones/{nronota}/cabecera', [CotizacionController::class, 'guardarCabecera'])->name('cotizaciones.cabecera.store')->whereNumber('nronota');
        Route::post('cotizaciones/{nronota}/factor', [CotizacionController::class, 'aplicarFactor'])->name('cotizaciones.factor')->whereNumber('nronota');
        Route::get('cotizaciones/{nronota}/envio-dex/catalogo', [CotizacionEnvioDexController::class, 'catalogo'])->name('cotizaciones.envio-dex.catalogo')->whereNumber('nronota');
        Route::post('cotizaciones/{nronota}/envio-dex/cotizar', [CotizacionEnvioDexController::class, 'cotizar'])->name('cotizaciones.envio-dex.cotizar')->whereNumber('nronota');
        Route::post('cotizaciones/{nronota}/importar-compra-agil/preview', [CotizacionController::class, 'importarCompraAgilPreview'])->name('cotizaciones.importar-compra-agil.preview')->whereNumber('nronota');
        Route::post('cotizaciones/{nronota}/importar-compra-agil/coincidencias', [CotizacionController::class, 'coincidenciasCompraAgil'])->name('cotizaciones.importar-compra-agil.coincidencias')->whereNumber('nronota');
        Route::post('cotizaciones/{nronota}/importar-compra-agil/limpiar-agile', [CotizacionController::class, 'limpiarLineasAgileCompraAgil'])->name('cotizaciones.importar-compra-agil.limpiar-agile')->whereNumber('nronota');
        Route::post('cotizaciones/{nronota}/importar-compra-agil', [CotizacionController::class, 'importarCompraAgil'])->name('cotizaciones.importar-compra-agil')->whereNumber('nronota');
        Route::post('cotizaciones/{nronota}/importar-pdf/preview', [CotizacionController::class, 'importarPdfPreview'])->name('cotizaciones.importar-pdf.preview')->whereNumber('nronota');
        Route::post('cotizaciones/{nronota}/importar-pdf', [CotizacionController::class, 'importarPdf'])->name('cotizaciones.importar-pdf')->whereNumber('nronota');
        Route::post('cotizaciones/{nronota}/importar-excel/preview', [CotizacionController::class, 'importarExcelPreview'])->name('cotizaciones.importar-excel.preview')->whereNumber('nronota');
        Route::post('cotizaciones/{nronota}/importar-excel', [CotizacionController::class, 'importarExcel'])->name('cotizaciones.importar-excel')->whereNumber('nronota');
        Route::get('cotizaciones/{nronota}/compra-agil-api/buscar', [CompraAgilBusquedaController::class, 'buscar'])->name('cotizaciones.compra-agil-api.buscar')->whereNumber('nronota');
        Route::post('cotizaciones/{nronota}/compra-agil-api/validar', [CompraAgilBusquedaController::class, 'validarCodigo'])->name('cotizaciones.compra-agil-api.validar')->whereNumber('nronota');
        Route::post('cotizaciones/{nronota}/compra-agil-api/preview', [CompraAgilBusquedaController::class, 'previewCodigo'])->name('cotizaciones.compra-agil-api.preview')->whereNumber('nronota');
        Route::post('cotizaciones/{nronota}/compra-agil-api/importar', [CompraAgilBusquedaController::class, 'importarCodigo'])->name('cotizaciones.compra-agil-api.importar')->whereNumber('nronota');
        Route::post('cotizaciones/{nronota}/lineas/lote', [CotizacionController::class, 'guardarLineasLote'])->name('cotizaciones.lineas.lote')->whereNumber('nronota');
        Route::post('cotizaciones/{nronota}/lineas/vincular-agile', [CotizacionController::class, 'vincularLineaAgile'])->name('cotizaciones.lineas.vincular-agile')->whereNumber('nronota');
        Route::post('cotizaciones/{nronota}/lineas', [CotizacionController::class, 'agregarLinea'])->name('cotizaciones.lineas.store')->whereNumber('nronota');
        Route::patch('cotizaciones/{nronota}/lineas/orden', [CotizacionController::class, 'cambiarOrdenLinea'])->name('cotizaciones.lineas.orden')->whereNumber('nronota');
        Route::delete('cotizaciones/{nronota}/lineas', [CotizacionController::class, 'eliminarLinea'])->name('cotizaciones.lineas.destroy')->whereNumber('nronota');

        Route::get('cotizaciones/{nronota}/export/pdf', [CotizacionExportController::class, 'pdf'])->name('cotizaciones.export.pdf')->whereNumber('nronota');
        Route::get('cotizaciones/{nronota}/export/archivo', [CotizacionExportController::class, 'archivo'])->name('cotizaciones.export.archivo')->whereNumber('nronota');
        Route::get('cotizaciones/{nronota}/export/excel', [CotizacionExportController::class, 'excel'])->name('cotizaciones.export.excel')->whereNumber('nronota');
        Route::get('cotizaciones/{nronota}/export/guia', [CotizacionExportController::class, 'guia'])->name('cotizaciones.export.guia')->whereNumber('nronota');
        Route::get('cotizaciones/{nronota}/export/guia-ingreso', [CotizacionExportController::class, 'guiaIngreso'])->name('cotizaciones.export.guia-ingreso')->whereNumber('nronota');

        Route::middleware('compra-agil-resultados')->group(function () {
            Route::get('compra-agil/resultados', [CompraAgilResultadosController::class, 'index'])->name('compra-agil.resultados.index');
            Route::post('compra-agil/resultados/iniciar', [CompraAgilResultadosController::class, 'iniciar'])->name('compra-agil.resultados.iniciar');
            Route::get('compra-agil/resultados/estado', [CompraAgilResultadosController::class, 'estado'])->name('compra-agil.resultados.estado');
            Route::post('compra-agil/resultados/cancelar', [CompraAgilResultadosController::class, 'cancelar'])->name('compra-agil.resultados.cancelar');
            Route::get('compra-agil/resultados/detalle/{nronota}', [CompraAgilResultadosController::class, 'detalle'])->name('compra-agil.resultados.detalle')->whereNumber('nronota');
            Route::get('compra-agil/resultados/resultado', [CompraAgilResultadosController::class, 'resultado'])->name('compra-agil.resultados.resultado');
            Route::get('compra-agil/resultados/cerradas', [CompraAgilResultadosController::class, 'cerradas'])->name('compra-agil.resultados.cerradas');
            Route::get('compra-agil/resultados/cerradas/exportar', [CompraAgilResultadosController::class, 'cerradasExportar'])->name('compra-agil.resultados.cerradas.exportar');
            Route::get('compra-agil/resultados/pendientes', [CompraAgilResultadosController::class, 'pendientes'])->name('compra-agil.resultados.pendientes');
            Route::get('compra-agil/resultados/pendientes/exportar', [CompraAgilResultadosController::class, 'pendientesExportar'])->name('compra-agil.resultados.pendientes.exportar');
            Route::get('compra-agil/resultados/segundo-llamado', [CompraAgilResultadosController::class, 'segundoLlamado'])->name('compra-agil.resultados.segundo-llamado');
            Route::get('compra-agil/resultados/segundo-llamado/exportar', [CompraAgilResultadosController::class, 'segundoLlamadoExportar'])->name('compra-agil.resultados.segundo-llamado.exportar');
            Route::get('compra-agil/resultados/todas', [CompraAgilResultadosController::class, 'todas'])->name('compra-agil.resultados.todas');
            Route::get('compra-agil/resultados/todas/exportar', [CompraAgilResultadosController::class, 'todasExportar'])->name('compra-agil.resultados.todas.exportar');
            Route::post('compra-agil/resultados/consultar/{nronota}', [CompraAgilResultadosController::class, 'consultarIndividual'])->name('compra-agil.resultados.consultar-individual')->whereNumber('nronota');
            Route::get('compra-agil/resultados/analisis-precios', [CompraAgilResultadosController::class, 'analisisPrecios'])->name('compra-agil.resultados.analisis-precios');
            Route::get('compra-agil/resultados/analisis-precios/exportar', [CompraAgilResultadosController::class, 'analisisPreciosExportar'])->name('compra-agil.resultados.analisis-precios.exportar');
        });

        Route::middleware('oportunidades-ver')->group(function () {
            Route::get('oportunidades/para-cotizar', [OportunidadParaCotizarController::class, 'index'])
                ->name('oportunidades.para-cotizar.index');
            Route::post('oportunidades/para-cotizar/visita', [OportunidadParaCotizarController::class, 'registrarVisita'])
                ->name('oportunidades.para-cotizar.visita');
            Route::get('oportunidades/para-cotizar/vinculo/{codigo}', [OportunidadParaCotizarController::class, 'detalleVinculo'])
                ->name('oportunidades.para-cotizar.detalle-vinculo')
                ->where('codigo', '[^/]+');
            Route::post('oportunidades/para-cotizar/vincular-codigo', [OportunidadParaCotizarController::class, 'vincularCodigo'])
                ->name('oportunidades.para-cotizar.vincular-codigo');
        });

        Route::middleware('oportunidades-admin')->group(function () {
            Route::post('oportunidades/para-cotizar/iniciar', [OportunidadParaCotizarController::class, 'iniciar'])
                ->name('oportunidades.para-cotizar.iniciar');
            Route::get('oportunidades/para-cotizar/estado', [OportunidadParaCotizarController::class, 'estado'])
                ->name('oportunidades.para-cotizar.estado');
            Route::post('oportunidades/para-cotizar/sync-par', [OportunidadParaCotizarController::class, 'sincronizarPar'])
                ->name('oportunidades.para-cotizar.sync-par');
            Route::post('oportunidades/para-cotizar/sync-par-inicio', [OportunidadParaCotizarController::class, 'sincronizarParInicio'])
                ->name('oportunidades.para-cotizar.sync-par-inicio');
            Route::post('oportunidades/para-cotizar/sync-par-lote', [OportunidadParaCotizarController::class, 'sincronizarParLote'])
                ->name('oportunidades.para-cotizar.sync-par-lote');
            Route::post('oportunidades/para-cotizar/cancelar', [OportunidadParaCotizarController::class, 'cancelar'])
                ->name('oportunidades.para-cotizar.cancelar');
            Route::post('oportunidades/para-cotizar/reanudar', [OportunidadParaCotizarController::class, 'reanudar'])
                ->name('oportunidades.para-cotizar.reanudar');
            Route::post('oportunidades/para-cotizar/iniciar-vinculo', [OportunidadParaCotizarController::class, 'iniciarVinculo'])
                ->name('oportunidades.para-cotizar.iniciar-vinculo');
            Route::post('oportunidades/para-cotizar/cancelar-vinculo', [OportunidadParaCotizarController::class, 'cancelarVinculo'])
                ->name('oportunidades.para-cotizar.cancelar-vinculo');
            Route::post('oportunidades/para-cotizar/paso', [OportunidadParaCotizarController::class, 'paso'])
                ->name('oportunidades.para-cotizar.paso');
        });

        Route::middleware('oportunidades-palabras')->group(function () {
            Route::get('oportunidades/palabras-clave', [OportunidadPalabraClaveController::class, 'index'])
                ->name('oportunidades.palabras-clave.index');
            Route::post('oportunidades/palabras-clave', [OportunidadPalabraClaveController::class, 'store'])
                ->name('oportunidades.palabras-clave.store');
            Route::post('oportunidades/palabras-clave/reordenar', [OportunidadPalabraClaveController::class, 'reordenar'])
                ->name('oportunidades.palabras-clave.reordenar');
            Route::post('oportunidades/palabras-clave/{palabra}/mover', [OportunidadPalabraClaveController::class, 'mover'])
                ->name('oportunidades.palabras-clave.mover');
            Route::delete('oportunidades/palabras-clave/{palabra}', [OportunidadPalabraClaveController::class, 'destroy'])
                ->name('oportunidades.palabras-clave.destroy');
        });

        Route::middleware('superadmin')->group(function () {
            Route::get('cotizaciones/adjudicadas', [AdjudicadaListadoController::class, 'index'])->name('cotizaciones.adjudicadas.index');
            Route::get('cotizaciones/adjudicadas/export/detalle', [AdjudicadaListadoController::class, 'exportDetalle'])->name('cotizaciones.adjudicadas.export.detalle');
            Route::get('cotizaciones/adjudicadas/export/sin-codigo-softland', [AdjudicadaListadoController::class, 'exportSinCodigoSoftland'])->name('cotizaciones.adjudicadas.export.sin-codigo-softland');

            Route::middleware('compra-agil-analisis')->group(function () {
                Route::get('compra-agil/analisis', [CompraAgilAnalisisController::class, 'index'])->name('compra-agil.analisis.index');
                Route::post('compra-agil/analisis/sync', [CompraAgilAnalisisController::class, 'sincronizar'])->name('compra-agil.analisis.sync');
                Route::get('compra-agil/analisis/producto/{prodItem}', [CompraAgilAnalisisController::class, 'detalleProducto'])->name('compra-agil.analisis.producto')->where('prodItem', '[^/]+');
            });

            Route::get('productos/carga-masiva', [MaeprodController::class, 'importForm'])->name('productos.import');
            Route::get('productos/carga-masiva/estado', [MaeprodController::class, 'importStatus'])->name('productos.import.status');
            Route::post('productos/carga-masiva/liberar', [MaeprodController::class, 'releaseImportLock'])->name('productos.import.unlock');
            Route::get('productos/carga-masiva/resultado/{run}', [MaeprodController::class, 'importResult'])->name('productos.import.resultado')->whereNumber('run');
            Route::get('productos/carga-masiva/errores/{run}', [MaeprodController::class, 'importErrors'])->name('productos.import.errores')->whereNumber('run');
            Route::get('productos/carga-masiva/errores/{run}/exportar', [MaeprodController::class, 'exportImportErrors'])->name('productos.import.errores.exportar')->whereNumber('run');
            Route::get('productos/carga-masiva/plantilla', [MaeprodController::class, 'downloadImportTemplate'])->name('productos.import.template');
            Route::get('productos/carga-masiva/plantilla-excel', [MaeprodController::class, 'downloadImportTemplateExcel'])->name('productos.import.template.excel');
            Route::post('productos/carga-masiva/chunk', [MaeprodController::class, 'storeImportChunk'])->name('productos.import.chunk');
            Route::post('productos/carga-masiva/inicializar', [MaeprodController::class, 'initializeCustomImport'])->name('productos.import.initialize');
            Route::post('productos/carga-masiva/vista-previa', [MaeprodController::class, 'previewImportMapping'])->name('productos.import.preview');
            Route::post('productos/carga-masiva/preparar-plantilla', [MaeprodController::class, 'prepareTemplateImport'])->name('productos.import.prepare.template');
            Route::post('productos/carga-masiva/preparar', [MaeprodController::class, 'prepareCustomImport'])->name('productos.import.prepare');
            Route::post('productos/carga-masiva/procesar', [MaeprodController::class, 'processImportBatch'])->name('productos.import.process');
            Route::post('productos/carga-masiva/procesar-background', [MaeprodController::class, 'startBackgroundImport'])->name('productos.import.background');
            Route::get('productos/carga-masiva/progreso', [MaeprodController::class, 'importProgress'])->name('productos.import.progress');
            Route::get('productos/exportar', [MaeprodController::class, 'exportCsv'])->name('productos.export');

            Route::get('productos/{prod_item}', [MaeprodController::class, 'edit'])->name('productos.edit')->where('prod_item', '[^/]+');
            Route::put('productos/{prod_item}', [MaeprodController::class, 'update'])->name('productos.update')->where('prod_item', '[^/]+');
            Route::delete('productos/{prod_item}', [MaeprodController::class, 'destroy'])->name('productos.destroy')->where('prod_item', '[^/]+');

            Route::get('usuarios', [UserController::class, 'index'])->name('users.index');
            Route::get('usuarios/nuevo', [UserController::class, 'create'])->name('users.create');
            Route::post('usuarios', [UserController::class, 'store'])->name('users.store');
            Route::get('usuarios/{usuario}/editar', [UserController::class, 'edit'])->name('users.edit');
            Route::put('usuarios/{usuario}', [UserController::class, 'update'])->name('users.update');
            Route::delete('usuarios/{usuario}', [UserController::class, 'destroy'])->name('users.destroy');

            Route::get('tarifas-correos-chile', [CorreosChileTarifaController::class, 'index'])->name('correos-chile.index');
            Route::post('tarifas-correos-chile/importar', [CorreosChileTarifaController::class, 'import'])->name('correos-chile.import');
        });
    });
});
