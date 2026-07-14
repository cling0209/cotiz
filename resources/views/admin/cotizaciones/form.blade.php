@extends('layouts.admin')

@section('title', ($desdeAdjudicadas ?? false) ? 'Cotizaciones adjudicadas' : 'Cotización '.$nota->nronota)

@push('head')
<link href="{{ asset('css/cotizacion-form.css') }}?v=img-zoom-50" rel="stylesheet">
@endpush

@section('content')
@php
    $desdeAdjudicadas = $desdeAdjudicadas ?? false;
    $mostrarSoftland = $mostrarSoftland ?? auth()->user()?->isSuperAdmin();
    $factorValor = (float) ($nota->factor_precio_venta ?? config('cotiz.factor_precio_venta'));
    $factorMostrado = number_format($factorValor, 2, ',', '');
    $factorInput = old('factor_precio_venta', $factorMostrado);
    $detalleColspan = ($desdeAdjudicadas ? 13 : 14) - ($mostrarSoftland ? 0 : 1);
@endphp

<div class="cotizacion-ingreso">
    <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-2">
        <h1 class="h5 mb-0">
            @if($desdeAdjudicadas)
                Cotizaciones adjudicadas
            @else
                Ingreso Cotizaci&oacute;n #{{ $nota->nronota }}
            @endif
        </h1>
        @if($desdeAdjudicadas)
            <a href="{{ route('admin.cotizaciones.adjudicadas.index') }}" class="btn btn-outline-secondary btn-sm">&larr; Adjudicadas</a>
        @else
            <a href="{{ route('admin.cotizaciones.index') }}" class="btn btn-outline-secondary btn-sm">&larr; Listado</a>
        @endif
    </div>

    @if($requiereNumeroCotizacion && ! $desdeAdjudicadas)
        <div class="alert alert-info py-2 mb-2" role="alert">
            Use <strong>Importar desde Compra &Aacute;gil</strong> para comenzar. El n&uacute;mero de cotizaci&oacute;n debe estar <strong>guardado</strong> antes de <strong>Agregar producto</strong> o analizar un <strong>PDF / Word</strong>.
        </div>
    @endif

    @if($hayPrecioAntiguo)
        <div class="alert alert-warning py-2 alert-precio-antiguo mb-2">
            Hay productos con precio de actualizaci&oacute;n anterior a {{ $umbralPrecioMeses }} mes(es) (fechas en rojo).
        </div>
    @endif

    <form method="post" action="{{ route('admin.cotizaciones.update', $nota->nronota) }}" id="form-cotizacion" data-no-loader>
        @csrf

        <fieldset class="cotiz-cabecera">
            <table id="tabla_datos1">
                <tr>
                    <th>Cliente</th>
                    <td><input type="text" name="empresa" id="empresa" maxlength="100" value="{{ old('empresa', $nota->empresa) }}"></td>
                    <th>
                        Cotizaci&oacute;n
                    </th>
                    <td>
                        <input
                            type="text"
                            name="encargado"
                            id="encargado"
                            maxlength="100"
                            value="{{ old('encargado', $nota->encargado) }}"
                            @class([
                                'cotiz-campo-numero-cotiz' => $requiereNumeroCotizacion || $errors->has('encargado'),
                                'is-invalid' => $errors->has('encargado'),
                            ])
                            placeholder="{{ $requiereNumeroCotizacion ? 'Se completa al importar o para PDF / Word' : '' }}"
                        >
                        @error('encargado')
                            <div class="text-danger small mt-1">{{ $message }}</div>
                        @enderror
                    </td>
                    <th>Celular</th>
                    <td><input type="text" name="celular" id="celular" maxlength="15" value="{{ old('celular', $nota->celular) }}"></td>
                </tr>
                <tr>
                    <th>Contacto</th>
                    <td><input type="text" name="contacto" id="contacto" maxlength="100" value="{{ old('contacto', $nota->contacto) }}"></td>
                    <th>E-mail</th>
                    <td><input type="text" name="contactocorreo" id="contactocorreo" maxlength="60" value="{{ old('contactocorreo', $nota->contactocorreo) }}"></td>
                    <th>Rut Empresa</th>
                    <td><input type="text" name="rutempresa" id="rutempresa" maxlength="10" value="{{ old('rutempresa', $nota->rutempresa) }}"></td>
                </tr>
                <tr>
                    <th>Monto Total</th>
                    <td><input type="text" name="montototal" id="montototal" readonly value="${{ number_format($total, 0, ',', '.') }}"></td>
                    <th>D&iacute;as H&aacute;biles</th>
                    <td><input type="number" name="diashabiles" id="diashabiles" min="0" maxlength="2" value="{{ old('diashabiles', $nota->diashabiles ?? 2) }}"></td>
                    <th>O.Compra</th>
                    <td><input type="text" name="ocompra" id="ocompra" maxlength="20" value="{{ old('ocompra', $nota->ocompra) }}"></td>
                </tr>
                <tr>
                    <th>Entrega</th>
                    <td><input type="date" name="fechaentrega" id="fechaentrega" value="{{ old('fechaentrega', $nota->fechaentrega?->format('Y-m-d')) }}"></td>
                    <th>Descripci&oacute;n</th>
                    <td colspan="3"><input type="text" name="descripcion" id="descripcion" maxlength="500" value="{{ old('descripcion', $nota->descripcion) }}" required></td>
                </tr>
            </table>

            @if($requiereNumeroCotizacion)
                <div class="cotiz-guardar-numero mt-2">
                    <button type="submit" name="accion" value="grabar" class="btn btn-primary btn-sm">Guardar n&uacute;mero</button>
                </div>
            @endif
        </fieldset>

        @if($desdeAdjudicadas)
            <p class="small text-muted mb-2" id="cotiz-resumen-lineas-actual">
                {{ $resumenLineas['total'] }} l&iacute;nea(s) en la cotizaci&oacute;n
                ({{ $resumenLineas['con_agile'] }} con ID Agile, {{ $resumenLineas['sin_agile'] }} sin ID Agile).
            </p>
        @endif

        <div class="cotiz-contenido-detalle">
        @unless($desdeAdjudicadas)
            <div class="cotiz-importar-mp mb-2 d-flex flex-wrap gap-2 align-items-center">
                <button type="button" class="btn btn-outline-primary btn-sm" id="btn-abrir-importar-compra-agil">
                    <i class="bi bi-clipboard-data"></i> Importar desde Compra &Aacute;gil
                </button>
                <span class="small text-muted" id="cotiz-resumen-lineas-actual">
                    {{ $resumenLineas['total'] }} l&iacute;nea(s) en la cotizaci&oacute;n
                    ({{ $resumenLineas['con_agile'] }} con ID Agile, {{ $resumenLineas['sin_agile'] }} sin ID Agile).
                </span>
                <span class="small text-muted">Importe desde Mercado P&uacute;blico, pegue texto, suba un PDF / Word o un Excel de listado de materiales.</span>
            </div>
        @endunless
            @unless($desdeAdjudicadas)
                <div id="notaventa-bloque-factor" class="cotiz-cabecera-factor mb-2">
                    <span class="d-inline-flex flex-wrap align-items-center column-gap-3 row-gap-2">
                        <span class="text-nowrap"><strong>&Uacute;ltimo factor guardado:</strong> <span id="factor_precio_venta_mostrado">{{ $factorMostrado }}</span></span>
                        <label for="factor_precio_venta" class="mb-0 text-nowrap"><strong>Factor Aumento Precio Venta:</strong></label>
                        <input type="text" name="factor_precio_venta" id="factor_precio_venta" size="7" maxlength="7" inputmode="decimal" autocomplete="off" title="Hasta 2 decimales (ej.: 1,30)" value="{{ $factorInput }}" @class(['is-invalid' => $errors->has('factor_precio_venta')])>
                        @error('factor_precio_venta')
                            <span class="text-danger small">{{ $message }}</span>
                        @enderror
                        <button type="button" class="btn btn-outline-primary btn-sm" id="btnFactorAumentoAceptar">Aplicar Nuevo Factor</button>
                    </span>
                </div>
            @else
                <p class="small text-muted mb-2"><strong>Factor de aumento:</strong> {{ $factorMostrado }}</p>
            @endunless

        @unless($desdeAdjudicadas)
        <div class="cotiz-agregar mb-2 d-flex flex-wrap gap-2 align-items-center">
            <button type="button" class="btn btn-success btn-sm" id="btn-abrir-buscar-producto">
                <i class="bi bi-plus-circle"></i> Agregar producto
            </button>
            @if(auth()->user()->isEjecutivo())
                <a href="{{ route('admin.productos.create') }}" class="btn btn-outline-primary btn-sm">
                    <i class="bi bi-box-seam"></i> Crear producto en maestro
                </a>
            @endif
        </div>
        @endunless

        <div id="notaventa-tabla-detalle-wrap" data-max-orden="{{ $lineas->count() }}">
            <table id="tabla_detalle" class="table table-sm">
                <thead>
                    <tr>
                        <th class="linea-drag-col" title="Arrastrar para reordenar"></th>
                        <th>Imagen</th>
                        <th>C&oacute;digo</th>
                        @if($mostrarSoftland)
                        <th>Cod. Softland</th>
                        @endif
                        <th>ID Agile</th>
                        <th>Descripci&oacute;n Agile (MP)</th>
                        <th>Descripci&oacute;n maestro</th>
                        <th>Fecha<br>act.&nbsp;precio</th>
                        <th>Precio Costo</th>
                        <th>Precio Unitario</th>
                        <th>Cantidad</th>
                        <th>Total</th>
                        <th>Orden</th>
                        @unless($desdeAdjudicadas)
                            <th>Eliminar</th>
                        @endunless
                    </tr>
                </thead>
                <tbody>
                    @forelse($lineas as $idx => $row)
                        @include('admin.cotizaciones.partials.linea-detalle-row', [
                            'idx' => $idx,
                            'row' => $row,
                            'isFirst' => $loop->first,
                            'isLast' => $loop->last,
                            'totalLineas' => $lineas->count(),
                            'desdeAdjudicadas' => $desdeAdjudicadas,
                            'mostrarSoftland' => $mostrarSoftland,
                        ])
                    @empty
                        <tr><td colspan="{{ $detalleColspan }}" class="text-muted text-center py-3">@if($desdeAdjudicadas)Sin l&iacute;neas.@else Sin l&iacute;neas. Use &laquo;Importar desde Compra &Aacute;gil&raquo; o &laquo;Agregar producto&raquo;.@endif</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        @if($lineas->isNotEmpty() && ! $desdeAdjudicadas)
        <div id="panelMercadoPublico" class="cotiz-panel-mp">
            <p class="cotiz-panel-mp__texto mb-2">
                <strong>Mercado P&uacute;blico</strong> &mdash; <em>valor unitario</em> por l&iacute;nea (usa <strong>ID Agile</strong> del maestro; si falta, el prefijo num&eacute;rico del c&oacute;digo interno). El valor despacho en la copia es siempre <strong>0</strong> (sin despacho).
            </p>
            <div class="d-flex flex-wrap align-items-center gap-2">
                <button type="button" id="btnCopiarMP" class="btn btn-outline-secondary btn-sm">Copiar para MP (portapapeles)</button>
                <span id="mpCopiaMsg" class="cotiz-panel-mp__msg" hidden></span>
            </div>
        </div>
        @endif

        <fieldset class="cotiz-botones">
            @unless($requiereNumeroCotizacion)
                <button type="submit" name="accion" value="grabar" class="btn btn-primary btn-sm">Grabar</button>
            @endunless
            <input type="hidden" name="nronota" id="nronota" value="{{ $nota->nronota }}">
            @if($lineas->isNotEmpty())
                <a href="{{ route('admin.cotizaciones.export.pdf', $nota->nronota) }}" class="btn btn-outline-secondary btn-sm">Descargar PDF</a>
                @unless($desdeAdjudicadas)
                    <a href="{{ route('admin.cotizaciones.export.archivo', $nota->nronota) }}" class="btn btn-outline-secondary btn-sm">Descargar Archivo</a>
                @endunless
                <a href="{{ route('admin.cotizaciones.export.excel', $nota->nronota) }}" class="btn btn-outline-secondary btn-sm">Descargar Excel</a>
                @unless($desdeAdjudicadas)
                    <a href="{{ route('admin.cotizaciones.export.guia', $nota->nronota) }}" class="btn btn-outline-secondary btn-sm">Descargar Gu&iacute;a</a>
                    <a href="{{ route('admin.cotizaciones.export.guia-ingreso', $nota->nronota) }}" class="btn btn-outline-secondary btn-sm">Descargar Gu&iacute;a Ingreso</a>
                @endunless
            @endif
        </fieldset>
        </div>
    </form>

    @unless($desdeAdjudicadas)
    <div id="cotiz-eliminar-lineas-forms">
    @foreach($lineas as $idx => $row)
        @include('admin.cotizaciones.partials.linea-detalle-delete-form', [
            'nota' => $nota,
            'row' => $row,
        ])
    @endforeach
    </div>
    @endunless

    @unless($desdeAdjudicadas)
    <div class="modal fade" id="modal-buscar-producto" tabindex="-1" aria-labelledby="modal-buscar-producto-label" aria-hidden="true">
        <div class="modal-dialog modal-lg modal-dialog-scrollable">
            <div class="modal-content">
                <div class="modal-header py-2">
                    <h2 class="modal-title fs-6" id="modal-buscar-producto-label">Buscar producto</h2>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Cerrar"></button>
                </div>
                <div class="modal-body py-2">
                    <div class="d-flex flex-wrap align-items-center gap-2 mb-2">
                        <div class="input-group input-group-sm flex-grow-1">
                            <span class="input-group-text"><i class="bi bi-search"></i></span>
                            <input
                                type="text"
                                id="modal-buscar-input"
                                class="form-control"
                                placeholder="Texto del cliente, c&oacute;digo o descripci&oacute;n..."
                                autocomplete="off"
                            >
                            <button type="button" class="btn btn-outline-secondary" id="btn-modal-buscar-limpiar" title="Limpiar búsqueda" aria-label="Limpiar búsqueda">
                                <i class="bi bi-x-lg"></i>
                            </button>
                            <button type="button" class="btn btn-primary" id="btn-modal-buscar">
                                Buscar
                            </button>
                        </div>
                        <div class="d-flex align-items-center gap-1">
                            <label for="modal-cantidad" class="small text-nowrap mb-0">Cantidad</label>
                            <input type="number" id="modal-cantidad" class="form-control form-control-sm" value="1" min="1" style="width:4.5rem">
                        </div>
                    </div>
                    <p id="modal-buscar-estado" class="small text-muted mb-2">Escriba el texto del cliente o descripci&oacute;n y pulse Buscar.</p>
                    <div class="table-responsive cotiz-buscar-tabla-wrap">
                        <table class="table table-sm table-hover mb-0 cotiz-buscar-tabla" id="tabla-buscar-productos">
                            <thead class="table-light sticky-top">
                                <tr>
                                    <th style="width:36px" class="text-center">
                                        <input type="checkbox" class="form-check-input" id="modal-buscar-seleccionar-todos" title="Seleccionar todos" aria-label="Seleccionar todos">
                                    </th>
                                    <th style="width:80px"></th>
                                    <th style="width:90px">C&oacute;digo</th>
                                    <th>Descripci&oacute;n</th>
                                    <th class="text-end" style="width:80px">Stock</th>
                                    <th class="text-end" style="width:90px">Precio</th>
                                </tr>
                            </thead>
                            <tbody id="modal-buscar-resultados">
                                <tr><td colspan="6" class="text-muted text-center py-3">Sin resultados.</td></tr>
                            </tbody>
                        </table>
                    </div>
                </div>
                <div class="modal-footer py-2">
                    <span class="small text-muted me-auto">Marque productos y pulse &laquo;Agregar seleccionados&raquo;.</span>
                    <button type="button" class="btn btn-success btn-sm" id="btn-modal-agregar-seleccionados" disabled>
                        <i class="bi bi-plus-circle"></i> Agregar seleccionados
                    </button>
                    <button type="button" class="btn btn-secondary btn-sm" data-bs-dismiss="modal">Cerrar</button>
                </div>
            </div>
        </div>
    </div>
    @endunless

    @unless($desdeAdjudicadas)
    <div class="modal fade" id="modal-importar-compra-agil" tabindex="-1" aria-labelledby="modal-importar-compra-agil-label" aria-hidden="true">
        <div class="modal-dialog modal-xl modal-dialog-scrollable">
            <div class="modal-content">
                <div class="modal-header py-2">
                    <h2 class="modal-title fs-6" id="modal-importar-compra-agil-label">Importar desde Compra &Aacute;gil</h2>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Cerrar"></button>
                </div>
                <div class="modal-body py-2">
                    <ul class="nav nav-tabs nav-tabs-sm mb-2" id="importar-ca-tabs" role="tablist">
                        <li class="nav-item" role="presentation">
                            <button class="nav-link active" id="tab-ca-codigo" data-bs-toggle="tab" data-bs-target="#panel-ca-codigo" type="button" role="tab">Por n&uacute;mero</button>
                        </li>
                        <li class="nav-item" role="presentation">
                            <button class="nav-link" id="tab-ca-pegar" data-bs-toggle="tab" data-bs-target="#panel-ca-pegar" type="button" role="tab">Pegar texto</button>
                        </li>
                        <li class="nav-item" role="presentation">
                            <button class="nav-link" id="tab-ca-pdf" data-bs-toggle="tab" data-bs-target="#panel-ca-pdf" type="button" role="tab">PDF y Word</button>
                        </li>
                        <li class="nav-item" role="presentation">
                            <button class="nav-link" id="tab-ca-excel" data-bs-toggle="tab" data-bs-target="#panel-ca-excel" type="button" role="tab">Excel</button>
                        </li>
                    </ul>
                    <p class="small mb-2" id="importar-compra-agil-detalle-actual">
                        <strong>Cotizaci&oacute;n actual:</strong>
                        <span id="importar-compra-agil-detalle-actual-texto">
                            {{ $resumenLineas['total'] }} l&iacute;nea(s)
                            ({{ $resumenLineas['con_agile'] }} con ID Agile, {{ $resumenLineas['sin_agile'] }} sin ID Agile).
                        </span>
                    </p>
                    <div class="tab-content">
                        <div class="tab-pane fade show active" id="panel-ca-codigo" role="tabpanel">
                            <div class="row g-2 mb-2">
                                <div class="col-md-6">
                                    <input type="text" id="ca-api-codigo" class="form-control form-control-sm font-monospace" placeholder="1161-172-COT26">
                                </div>
                                <div class="col-md-auto">
                                    <button type="button" class="btn btn-primary btn-sm" id="btn-ca-buscar-codigo"><i class="bi bi-hash"></i> Cargar</button>
                                </div>
                            </div>
                        </div>
                        <div class="tab-pane fade" id="panel-ca-pegar" role="tabpanel">
                            <p class="small text-muted mb-2">Copie el texto desde la p&aacute;gina de Mercado P&uacute;blico.</p>
                            <textarea
                                id="importar-compra-agil-texto"
                                class="form-control form-control-sm font-monospace mb-2"
                                rows="8"
                                placeholder="Detalle de la cotización 1161-172-COT26&#10;Nombre&#10;...&#10;SERVICIO AGRICOLA Y GANADERO&#10;RUT 61.303.000-7&#10;..."
                            ></textarea>
                            <button type="button" class="btn btn-primary btn-sm" id="btn-importar-compra-agil-analizar">
                                <i class="bi bi-search"></i> Analizar texto pegado
                            </button>
                        </div>
                        <div class="tab-pane fade" id="panel-ca-pdf" role="tabpanel">
                            <p class="small text-muted mb-2">Suba un PDF o Word (.docx) con listado de materiales (cantidad y producto). Si el PDF est&aacute; escaneado se intentar&aacute; OCR. Se sugerir&aacute; el producto del maestro m&aacute;s parecido; use Buscar en cada fila si hace falta.</p>
                            @if($requiereNumeroCotizacion)
                                <div class="alert alert-warning py-2 px-3 small mb-2">
                                    Puede analizar sin n&uacute;mero; al importar deber&aacute; tener ingresado el n&uacute;mero de cotizaci&oacute;n y pulsar <strong>Guardar n&uacute;mero</strong>.
                                </div>
                            @endif
                            <input
                                type="file"
                                id="importar-compra-agil-pdf"
                                class="form-control form-control-sm mb-2"
                                accept=".pdf,.docx,application/pdf,application/vnd.openxmlformats-officedocument.wordprocessingml.document"
                            >
                            <button type="button" class="btn btn-primary btn-sm" id="btn-importar-compra-agil-analizar-pdf">
                                <i class="bi bi-file-earmark-text"></i> Analizar PDF / Word
                            </button>
                        </div>
                        <div class="tab-pane fade" id="panel-ca-excel" role="tabpanel">
                            <p class="small text-muted mb-2">
                                Suba un Excel (.xlsx / .xls / .csv) e indique las columnas de <strong>cantidad</strong> y <strong>producto</strong>
                                (letra A, B, C&hellip; o n&uacute;mero). Por defecto: A = cantidad, B = producto.
                                Se omiten filas vac&iacute;as, t&iacute;tulos y totales; cada fila v&aacute;lida se importa como l&iacute;nea aparte.
                            </p>
                            @if($requiereNumeroCotizacion)
                                <div class="alert alert-warning py-2 px-3 small mb-2">
                                    Puede analizar sin n&uacute;mero; al importar deber&aacute; tener ingresado el n&uacute;mero de cotizaci&oacute;n y pulsar <strong>Guardar n&uacute;mero</strong>.
                                </div>
                            @endif
                            <div class="row g-2 mb-2">
                                <div class="col-md-4">
                                    <label class="form-label form-label-sm mb-0" for="importar-compra-agil-excel-col-cant">Columna cantidad</label>
                                    <input type="text" id="importar-compra-agil-excel-col-cant" class="form-control form-control-sm text-uppercase" value="A" maxlength="3" placeholder="A">
                                </div>
                                <div class="col-md-4">
                                    <label class="form-label form-label-sm mb-0" for="importar-compra-agil-excel-col-desc">Columna producto</label>
                                    <input type="text" id="importar-compra-agil-excel-col-desc" class="form-control form-control-sm text-uppercase" value="B" maxlength="3" placeholder="B">
                                </div>
                            </div>
                            <input
                                type="file"
                                id="importar-compra-agil-excel"
                                class="form-control form-control-sm mb-2"
                                accept=".xlsx,.xls,.csv,application/vnd.openxmlformats-officedocument.spreadsheetml.sheet,application/vnd.ms-excel,text/csv"
                            >
                            <button type="button" class="btn btn-primary btn-sm" id="btn-importar-compra-agil-analizar-excel">
                                <i class="bi bi-file-earmark-spreadsheet"></i> Analizar Excel
                            </button>
                        </div>
                    </div>
                    <div id="importar-compra-agil-alerta" class="alert alert-danger py-2 px-3 small mb-2 d-none mt-2" role="alert">
                        <i class="bi bi-exclamation-octagon-fill me-1"></i>
                        <span id="importar-compra-agil-alerta-texto"></span>
                    </div>
                    <div id="importar-compra-agil-consulta-par" class="alert alert-info py-2 px-3 small mb-2 d-none mt-2" role="status">
                        <i class="bi bi-cloud-check me-1"></i>
                        <span id="importar-compra-agil-consulta-par-texto"></span>
                    </div>
                    <div class="d-flex flex-wrap align-items-center gap-2 mb-2">
                        <span id="importar-compra-agil-estado" class="small text-muted"></span>
                    </div>
                    <div id="importar-compra-agil-progreso-wrap" class="d-none w-100 mb-2">
                        <div class="progress" style="height:14px">
                            <div id="importar-compra-agil-progreso" class="progress-bar progress-bar-striped progress-bar-animated bg-primary" role="progressbar" style="width:0%" aria-valuenow="0" aria-valuemin="0" aria-valuemax="100">0%</div>
                        </div>
                        <p id="importar-compra-agil-progreso-texto" class="small text-primary fw-semibold mb-0 mt-1">Preparando importaci&oacute;n...</p>
                    </div>
                    <div id="importar-compra-agil-cabecera" class="small mb-2 d-none">
                        <strong>Cabecera detectada:</strong>
                        <span id="importar-compra-agil-cabecera-texto"></span>
                    </div>
                    <div class="table-responsive d-none" id="importar-compra-agil-tabla-wrap">
                        <table class="table table-sm table-hover mb-0">
                            <thead class="table-light sticky-top">
                                <tr>
                                    <th style="width:90px">ID Agile</th>
                                    <th>Descripci&oacute;n MP</th>
                                    <th style="width:70px">Cant.</th>
                                    <th style="width:100px">C&oacute;digo</th>
                                    <th>Producto maestro</th>
                                    <th style="width:80px">Estado</th>
                                </tr>
                            </thead>
                            <tbody id="importar-compra-agil-resultados"></tbody>
                        </table>
                    </div>
                </div>
                <div class="modal-footer flex-column align-items-stretch gap-2 py-2">
                    <div class="d-flex w-100 flex-wrap align-items-center gap-2">
                        <span class="small text-muted me-auto" id="importar-compra-agil-resumen"></span>
                        <button type="button" class="btn btn-secondary btn-sm" data-bs-dismiss="modal">Cerrar</button>
                        <button type="button" class="btn btn-success btn-sm d-none" id="btn-importar-compra-agil-confirmar">
                            <i class="bi bi-download"></i> Importar
                        </button>
                    </div>
                </div>
            </div>
        </div>
    </div>
    @endunless

    <div id="popupVincularAgile" class="cotiz-popup-overlay" style="display:none">
        <div class="cotiz-popup-content">
            <div class="cotiz-popup-header">
                <h5 class="mb-0">Buscar producto maestro</h5>
                <button type="button" class="btn-close" id="cerrarPopupVincularAgile" aria-label="Cerrar"></button>
            </div>
            <div class="cotiz-popup-body">
                <div class="cotiz-popup-buscar">
                    <p class="small text-muted mb-2"><strong>Descripci&oacute;n Compra &Aacute;gil:</strong> <span id="popupVincularDescAgile"></span></p>
                    <div class="input-group input-group-sm mb-0">
                        <input type="text" id="popupVincularBusqueda" class="form-control" placeholder="C&oacute;digo o nombre">
                        <button type="button" class="btn btn-outline-secondary" id="btnPopupVincularLimpiar" title="Limpiar búsqueda" aria-label="Limpiar búsqueda">
                            <i class="bi bi-x-lg"></i>
                        </button>
                        <button type="button" class="btn btn-secondary" id="btnPopupVincularBuscar">Buscar</button>
                    </div>
                </div>
                <div id="popupVincularResultados" class="cotiz-popup-resultados"></div>
            </div>
        </div>
    </div>

    <div class="modal fade" id="modal-imagen-producto-cotiz" tabindex="-1" aria-labelledby="modal-imagen-producto-cotiz-titulo" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered modal-lg">
            <div class="modal-content">
                <div class="modal-header py-2">
                    <h2 class="modal-title fs-6" id="modal-imagen-producto-cotiz-titulo"></h2>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Cerrar"></button>
                </div>
                <div class="modal-body text-center p-2 bg-light">
                    <img id="modal-imagen-producto-cotiz-img" src="" alt="" class="img-fluid rounded shadow-sm product-image-zoom-preview">
                </div>
            </div>
        </div>
    </div>
</div>
@endsection

@push('scripts')
<script src="https://cdn.jsdelivr.net/npm/sortablejs@1.15.6/Sortable.min.js"></script>
<script src="{{ asset('js/product-image.js') }}" defer></script>
<script>
(function () {
    const requiereNumeroCotizacion = @json($requiereNumeroCotizacion);
    const abrirImportarAlInicio = @json($abrirImportarAlInicio ?? false);
    const desdeAdjudicadas = @json($desdeAdjudicadas);
    const detalleColspan = @json($detalleColspan);
    const mensajeSinLineas = desdeAdjudicadas
        ? 'Sin líneas.'
        : 'Sin líneas. Use «Importar desde Compra Ágil» o «Agregar producto».';

    function dlgAlert(message, opts = {}) {
        if (window.AdminDialog) {
            return AdminDialog.alert(message, { type: 'warning', ...opts });
        }
        alert(message);
    }

    function dlgConfirm(message, opts = {}) {
        if (window.AdminDialog) {
            return AdminDialog.confirm(message, opts);
        }
        return Promise.resolve(confirm(message));
    }

    function asegurarNumeroCotizacionGuardada(opciones = {}) {
        if (!requiereNumeroCotizacion) {
            return true;
        }
        const enc = document.getElementById('encargado');
        const valor = String(enc?.value || '').trim();
        const titulo = opciones.titulo || 'Cotización';
        if (!valor) {
            dlgAlert(opciones.mensajeVacio || 'Debe ingresar la cotización.', { title: titulo });
            enc?.focus();
            return false;
        }
        dlgAlert(
            opciones.mensajeGuardar || 'Guarde la cotización con el botón «Guardar número» antes de continuar.',
            { title: titulo },
        );
        enc?.focus();
        return false;
    }

    const fmt = n => '$' + Math.round(n).toLocaleString('es-CL');

    function codigoProductoTexto(val) {
        if (val == null || val === '') return '';
        if (typeof val === 'number' && Number.isFinite(val)) {
            return Number.isInteger(val)
                ? String(val)
                : val.toLocaleString('fullwide', { useGrouping: false, maximumFractionDigits: 0 });
        }
        const s = String(val).trim();
        const m = s.replace(/\s/g, '').match(/^([\d]+(?:[,\.]\d+)?)[eE]([+\-]?\d+)$/);
        if (!m) return s;
        const mantissa = parseFloat(m[1].replace(',', '.'));
        const exp = parseInt(m[2], 10);
        if (!Number.isFinite(mantissa) || !Number.isFinite(exp)) return s;
        return (mantissa * Math.pow(10, exp)).toLocaleString('fullwide', { useGrouping: false, maximumFractionDigits: 0 });
    }

    const montototal = document.getElementById('montototal');
    const factorInput = document.getElementById('factor_precio_venta');
    const factorUrl = @json(route('admin.cotizaciones.factor', $nota->nronota));
    const cabeceraUrl = @json(route('admin.cotizaciones.cabecera.store', $nota->nronota));
    const lineasLoteUrl = @json(route('admin.cotizaciones.lineas.lote', $nota->nronota));
    const encargadoActual = @json(trim((string) $nota->encargado));
    const consultaParValidarUrl = @json(route('admin.cotizaciones.compra-agil-api.validar', $nota->nronota));
    const consultaParConfig = {
        mensaje: @json(config('cotiz.api_nota.consulta_par_mensaje_iniciando')),
        maxIntentos: @json((int) config('cotiz.api_nota.consulta_par_max_intentos', 8)),
        esperaMs: @json((int) config('cotiz.api_nota.consulta_par_espera_segundos', 3) * 1000),
    };
    const lineasPorLote = 10;
    const btnFactorAumento = document.getElementById('btnFactorAumentoAceptar');

    function parseFactorChile(texto) {
        let t = String(texto ?? '').trim().replace(/\s/g, '');
        if (!t) return null;
        if (/^\d+[,.]$/.test(t)) {
            t = t.slice(0, -1);
        }
        if (!t) return null;
        const norm = t.includes(',') ? t.replace(/\./g, '').replace(',', '.') : t;
        if (!/^\d+(?:\.\d{1,2})?$/.test(norm)) return null;
        const f = parseFloat(norm);
        return Number.isFinite(f) && f > 0 ? Math.round(f * 100) / 100 : null;
    }

    function formatFactorChile(f) {
        return f.toFixed(2).replace('.', ',');
    }

    factorInput?.addEventListener('input', function () {
        let out = '';
        let sepUsed = false;
        for (const ch of String(this.value || '')) {
            if (ch >= '0' && ch <= '9') {
                out += ch;
            } else if ((ch === ',' || ch === '.') && !sepUsed) {
                out += ch;
                sepUsed = true;
            }
        }
        if (out !== this.value) {
            this.value = out;
        }
        this.classList.remove('is-invalid');
    });

    factorInput?.addEventListener('blur', function () {
        const parsed = parseFactorChile(this.value);
        if (parsed === null) {
            if (String(this.value || '').trim() !== '') {
                this.classList.add('is-invalid');
            }
            return;
        }
        this.classList.remove('is-invalid');
        this.value = formatFactorChile(parsed);
    });

    function setLoaderMensaje(texto) {
        const loader = document.getElementById('page-loader');
        if (!loader) return;
        let msg = loader.querySelector('.page-loader__msg');
        if (!msg) {
            msg = document.createElement('p');
            msg.className = 'page-loader__msg small text-white mt-2 mb-0 text-center px-3';
            loader.querySelector('.page-loader__scene')?.appendChild(msg);
        }
        msg.textContent = texto || '';
        msg.hidden = !texto;
    }

    function extraerMensajeError(json, fallback) {
        if (json?.error) return json.error;
        if (json?.message) return json.message;
        if (json?.errors) {
            const first = Object.values(json.errors)[0];
            if (Array.isArray(first) && first[0]) return first[0];
        }
        return fallback;
    }

    function collectCabeceraFromForm() {
        const form = document.getElementById('form-cotizacion');
        const val = (name) => form?.querySelector('[name="' + name + '"]')?.value ?? '';
        const payload = {
            descripcion: val('descripcion'),
            empresa: val('empresa'),
            encargado: val('encargado'),
            celular: val('celular'),
            contacto: val('contacto'),
            contactocorreo: val('contactocorreo'),
            rutempresa: val('rutempresa'),
            diashabiles: val('diashabiles') !== '' ? parseInt(val('diashabiles'), 10) : null,
            ocompra: val('ocompra'),
            fechaentrega: val('fechaentrega') || null,
        };
        if (factorInput && String(factorInput.value || '').trim() !== '') {
            payload.factor_precio_venta = factorInput.value;
        }
        return payload;
    }

    function collectLineasFromTable() {
        syncLineasHiddenDesdeDataset();
        const lineas = [];
        document.querySelectorAll('#tabla_detalle tbody tr[data-linea]').forEach(function (tr) {
            const prodItem = String(tr.dataset.prod || tr.querySelector('input[name*="[prod_item]"]')?.value || '').trim();
            const ordenRaw = tr.dataset.orden || tr.querySelector('input[name*="[orden]"]')?.value;
            const orden = parseInt(String(ordenRaw || ''), 10);
            if (!prodItem || Number.isNaN(orden)) return;

            const linea = { prod_item: prodItem, orden: orden };
            const softland = tr.querySelector('input[name*="[prod_item_softland]"]');
            const costo = tr.querySelector('input[name*="[prod_valor_costo]"]');
            const valor = tr.querySelector('input[name*="[prod_valor]"]');
            const cantidad = tr.querySelector('input[name*="[cantidad]"]');
            if (softland) linea.prod_item_softland = softland.value;
            if (costo && costo.value !== '') linea.prod_valor_costo = parseInt(costo.value, 10);
            if (valor && valor.value !== '') linea.prod_valor = parseInt(valor.value, 10);
            if (cantidad && cantidad.value !== '') linea.cantidad = parseInt(cantidad.value, 10);
            lineas.push(linea);
        });
        return lineas;
    }

    function syncLineasHiddenDesdeDataset() {
        document.querySelectorAll('#tabla_detalle tbody tr[data-linea]').forEach(function (tr) {
            const prod = String(tr.dataset.prod || '').trim();
            const orden = String(tr.dataset.orden || '').trim();
            const prodHidden = tr.querySelector('input[name*="[prod_item]"]');
            const ordenHidden = tr.querySelector('input[name*="[orden]"]');
            if (prodHidden && prod !== '') prodHidden.value = prod;
            if (ordenHidden && orden !== '') ordenHidden.value = orden;
        });
    }

    function chunkArray(items, size) {
        const chunks = [];
        for (let i = 0; i < items.length; i += size) {
            chunks.push(items.slice(i, i + size));
        }
        return chunks;
    }

    async function postJson(url, body) {
        const csrfToken = document.querySelector('meta[name="csrf-token"]')?.content;
        const res = await fetch(url, {
            method: 'POST',
            headers: {
                'Accept': 'application/json',
                'Content-Type': 'application/json',
                'X-CSRF-TOKEN': csrfToken,
                'X-Requested-With': 'XMLHttpRequest',
            },
            body: JSON.stringify(body),
        });
        const json = await res.json().catch(() => ({}));
        return { res, json };
    }

    function sleepMs(ms) {
        return new Promise((resolve) => setTimeout(resolve, ms));
    }

    function necesitaConsultaParEncargado(encargado) {
        const numero = String(encargado || '').trim();
        if (numero === '') return false;

        return numero.localeCompare(String(encargadoActual || '').trim(), undefined, { sensitivity: 'accent' }) !== 0;
    }

    async function fetchValidarEncargadoPar(codigo, csrfValue) {
        const body = new FormData();
        body.append('_token', csrfValue || '');
        body.append('codigo', String(codigo || '').trim().toUpperCase());
        const res = await fetch(consultaParValidarUrl, {
            method: 'POST',
            headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
            body,
        });
        const json = await res.json().catch(() => ({}));

        return { res, json };
    }

    function esRespuestaColdStartConsultaPar(res, json) {
        if (json?.cold_start === true) {
            return true;
        }

        return res.status === 503 && String(json?.message || '').trim() === String(consultaParConfig.mensaje || '').trim();
    }

    function mensajeErrorSinConexionConsultaPar() {
        return 'No se pudo conectar con el servicio de consulta. Intente nuevamente en unos momentos.';
    }

    async function validarEncargadoParConEspera(codigo, opciones = {}) {
        const token = opciones.csrf || document.querySelector('meta[name="csrf-token"]')?.content || '';
        const max = consultaParConfig.maxIntentos;
        const mensajeIniciando = consultaParConfig.mensaje;
        let enColdStart = false;

        for (let intento = 1; intento <= max; intento++) {
            if (enColdStart) {
                opciones.onProgress?.(intento, max, mensajeIniciando);
            }

            const { res, json } = await fetchValidarEncargadoPar(codigo, token);
            if (res.ok && json.ok) {
                opciones.onSuccess?.(json);

                return json;
            }

            if (esRespuestaColdStartConsultaPar(res, json)) {
                enColdStart = true;
                opciones.onProgress?.(intento, max, mensajeIniciando);
                if (intento >= max) {
                    throw new Error(mensajeErrorSinConexionConsultaPar());
                }
                await sleepMs(consultaParConfig.esperaMs);
                continue;
            }

            throw new Error(extraerMensajeError(json, 'No se puede usar este número de cotización.'));
        }

        throw new Error(mensajeErrorSinConexionConsultaPar());
    }

    let grabandoCotizacion = false;

    async function grabarCotizacionAjax() {
        if (grabandoCotizacion) return;
        grabandoCotizacion = true;

        const form = document.getElementById('form-cotizacion');
        const botonesGrabar = form?.querySelectorAll('button[name="accion"][value="grabar"]') ?? [];
        botonesGrabar.forEach((btn) => { btn.disabled = true; });

        mostrarLoaderCotiz();
        setLoaderMensaje('Guardando cabecera…');

        try {
            const cabecera = collectCabeceraFromForm();

            if (necesitaConsultaParEncargado(cabecera.encargado)) {
                setLoaderMensaje('Verificando cotización en el otro sitio…');
                await validarEncargadoParConEspera(cabecera.encargado, {
                    onProgress: (_intento, _max, msg) => setLoaderMensaje(msg),
                });
            }

            setLoaderMensaje('Guardando cabecera…');
            const { res: resCab, json: jsonCab } = await postJson(cabeceraUrl, cabecera);
            if (!resCab.ok) {
                throw new Error(extraerMensajeError(jsonCab, 'No se pudo guardar la cabecera.'));
            }

            const lineas = collectLineasFromTable();
            const lotes = chunkArray(lineas, lineasPorLote);
            let guardadasTotal = 0;

            for (let i = 0; i < lotes.length; i++) {
                setLoaderMensaje('Guardando detalle ' + (i + 1) + ' de ' + lotes.length + '…');
                const { res, json } = await postJson(lineasLoteUrl, { lineas: lotes[i] });
                if (!res.ok) {
                    const parcial = guardadasTotal > 0
                        ? ' Se guardaron ' + guardadasTotal + ' de ' + lineas.length + ' líneas.'
                        : '';
                    throw new Error(extraerMensajeError(json, 'No se pudo guardar el detalle.') + parcial);
                }
                guardadasTotal += json.guardadas ?? lotes[i].length;
            }

            setLoaderMensaje('');
            ocultarLoaderCotiz();
            try {
                sessionStorage.setItem('page-loader-pending', '1');
            } catch (e) {}
            dlgAlert(jsonCab.mensaje || 'Cotización guardada.', { title: 'Guardado', type: 'success' });
            window.location.reload();
        } catch (err) {
            setLoaderMensaje('');
            ocultarLoaderCotiz();
            dlgAlert(err?.message || 'Error al guardar la cotización.', { title: 'Error', type: 'danger' });
            botonesGrabar.forEach((btn) => { btn.disabled = false; });
            grabandoCotizacion = false;
        }
    }

    document.getElementById('form-cotizacion')?.addEventListener('submit', function (e) {
        const submitter = e.submitter;
        if (!submitter || submitter.name !== 'accion') return;
        if (submitter.value !== 'grabar') return;

        e.preventDefault();

        if (!this.checkValidity()) {
            this.reportValidity();
            return;
        }

        if (factorInput && String(factorInput.value || '').trim() !== '') {
            const parsed = parseFactorChile(factorInput.value);
            if (parsed === null) {
                factorInput.classList.add('is-invalid');
                factorInput.focus();
                dlgAlert('El factor debe ser un número positivo con hasta 2 decimales (ej.: 1,30).', { title: 'Factor inválido' });
                return;
            }
            factorInput.value = formatFactorChile(parsed);
        }

        grabarCotizacionAjax();
    });

    function encontrarFilaPorOrdenProd(orden, prodItem) {
        const porProd = document.querySelector(
            '#tabla_detalle tbody tr[data-orden="' + orden + '"][data-prod="' + CSS.escape(String(prodItem || '')) + '"]'
        );
        if (porProd) return porProd;
        return document.querySelector('#tabla_detalle tbody tr[data-orden="' + orden + '"]');
    }

    async function aplicarFactorAjax() {
        if (!factorInput || !btnFactorAumento) return;

        const parsed = parseFactorChile(factorInput.value);
        if (parsed === null) {
            factorInput.classList.add('is-invalid');
            factorInput.focus();
            dlgAlert('El factor debe ser un número positivo con hasta 2 decimales (ej.: 1,30).', { title: 'Factor inválido' });
            return;
        }

        factorInput.classList.remove('is-invalid');
        factorInput.value = formatFactorChile(parsed);

        const labelOriginal = btnFactorAumento.textContent;
        btnFactorAumento.disabled = true;
        btnFactorAumento.textContent = 'Aplicando...';

        try {
            const res = await fetch(factorUrl, {
                method: 'POST',
                headers: {
                    'Accept': 'application/json',
                    'Content-Type': 'application/json',
                    'X-CSRF-TOKEN': csrf,
                    'X-Requested-With': 'XMLHttpRequest',
                },
                body: JSON.stringify({ factor_precio_venta: factorInput.value }),
            });
            const json = await res.json().catch(() => ({}));

            if (!res.ok) {
                dlgAlert(json.error || json.message || 'No se pudo aplicar el factor.', { title: 'Error', type: 'danger' });
                return;
            }

            const factorMostrado = document.getElementById('factor_precio_venta_mostrado');
            if (factorMostrado && json.factor_precio_venta_fmt) {
                factorMostrado.textContent = json.factor_precio_venta_fmt;
            }

            let preciosCambiados = 0;
            let lineasSinCosto = 0;

            (json.lineas || []).forEach(linea => {
                const tr = encontrarFilaPorOrdenProd(linea.orden, linea.prod_item);
                if (!tr) return;

                const costo = parseInt(linea.prod_valor_costo, 10) || 0;
                if (costo <= 0) lineasSinCosto++;

                const ventaInput = tr.querySelector('.linea-prod-valor');
                const ventaAnterior = ventaInput ? parseInt(ventaInput.value || '0', 10) : 0;
                const ventaNueva = parseInt(linea.prod_valor, 10) || 0;

                if (ventaInput) ventaInput.value = ventaNueva;

                const totalTd = tr.querySelector('.linea-total');
                if (totalTd) totalTd.textContent = fmt(linea.subtotal ?? (ventaNueva * (parseInt(tr.querySelector('.linea-cantidad')?.value || '1', 10) || 1)));

                if (ventaNueva !== ventaAnterior) preciosCambiados++;
            });

            recalcularMontoTotal();

            let mensaje = 'Factor ' + (json.factor_precio_venta_fmt || factorInput.value) + ' guardado.';
            if (preciosCambiados > 0) {
                mensaje += ' ' + preciosCambiados + ' precio' + (preciosCambiados === 1 ? '' : 's') + ' actualizado' + (preciosCambiados === 1 ? '' : 's') + '.';
            } else {
                mensaje += ' Los precios ya coincidían con ese factor.';
            }
            if (lineasSinCosto > 0) {
                mensaje += ' ' + lineasSinCosto + ' línea' + (lineasSinCosto === 1 ? '' : 's') + ' sin costo (no se recalcula venta).';
            }

            dlgAlert(mensaje, { title: 'Factor aplicado', type: 'success' });
        } catch (err) {
            dlgAlert('Error de conexión al aplicar el factor.', { title: 'Error', type: 'danger' });
        } finally {
            btnFactorAumento.disabled = false;
            btnFactorAumento.textContent = labelOriginal;
        }
    }

    btnFactorAumento?.addEventListener('click', aplicarFactorAjax);

    function marcarLineasRepetidas() {
        const rows = document.querySelectorAll('#tabla_detalle tbody tr[data-prod]');
        const counts = {};
        rows.forEach(tr => {
            const prod = String(tr.dataset.prod || '').trim();
            if (!prod) return;
            counts[prod] = (counts[prod] || 0) + 1;
        });
        rows.forEach(tr => {
            const prod = String(tr.dataset.prod || '').trim();
            tr.classList.toggle('linea-repetida', prod && counts[prod] > 1);
        });
    }

    function recalcularMontoTotal() {
        let sum = 0;
        document.querySelectorAll('#tabla_detalle tbody tr[data-linea]').forEach(tr => {
            const valor = parseInt(tr.querySelector('.linea-prod-valor')?.value || '0', 10);
            const cant = parseInt(tr.querySelector('.linea-cantidad')?.value || '0', 10);
            const total = valor * cant;
            const td = tr.querySelector('.linea-total');
            if (td) td.textContent = fmt(total);
            sum += total;
        });
        if (montototal) montototal.value = fmt(sum);
    }

    marcarLineasRepetidas();

    document.getElementById('tabla_detalle')?.addEventListener('input', e => {
        if (e.target.matches('.linea-prod-valor, .linea-cantidad')) {
            recalcularMontoTotal();
        }
    });

    document.querySelectorAll('#tabla_detalle tbody tr[data-linea]').forEach(tr => {
        if (!desdeAdjudicadas) wireEliminarLinea(tr);
    });

    function quitarLineaDetalle(tr, delForm) {
        tr?.remove();
        delForm?.remove();

        const tbody = document.querySelector('#tabla_detalle tbody');
        if (tbody && !tbody.querySelector('tr[data-linea]')) {
            tbody.innerHTML = '<tr><td colspan="' + detalleColspan + '" class="text-muted text-center py-3">' + mensajeSinLineas + '</td></tr>';
        }

        marcarLineasRepetidas();
        actualizarControlesOrdenVisual();
        recalcularMontoTotal();
    }

    async function eliminarLineaAjax(tr, delForm) {
        const prod = delForm.dataset.prod;
        const orden = delForm.dataset.orden;
        const btn = tr.querySelector('.eliminar-cell button');

        if (btn) {
            btn.disabled = true;
            btn.textContent = 'Eliminando...';
        }

        try {
            const res = await fetch(delForm.action, {
                method: 'DELETE',
                headers: {
                    'Accept': 'application/json',
                    'Content-Type': 'application/json',
                    'X-CSRF-TOKEN': csrf,
                    'X-Requested-With': 'XMLHttpRequest',
                },
                body: JSON.stringify({
                    prod_item: prod,
                    orden: parseInt(orden, 10),
                }),
            });
            const json = await res.json().catch(() => ({}));

            if (!res.ok) {
                if (btn) {
                    btn.disabled = false;
                    btn.textContent = 'Eliminar';
                }
                dlgAlert(json.error || json.message || 'No se pudo eliminar la línea.', { title: 'Error', type: 'danger' });
                return;
            }

            quitarLineaDetalle(tr, delForm);
            if (json.lineas) {
                aplicarOrdenDesdeServidor(json.lineas);
            }
            if (json.resumen) {
                actualizarResumenLineas(json.resumen);
            }
        } catch (err) {
            if (btn) {
                btn.disabled = false;
                btn.textContent = 'Eliminar';
            }
            dlgAlert('Error de conexión al eliminar la línea.', { title: 'Error', type: 'danger' });
        }
    }

    function wireEliminarLinea(tr) {
        const elimTd = tr.querySelector('.eliminar-cell');
        if (!elimTd || elimTd.querySelector('button')) return;
        const prod = elimTd.dataset.prod;
        const orden = elimTd.dataset.orden;
        const delForm = document.querySelector('.form-eliminar-linea[data-prod="' + prod + '"][data-orden="' + orden + '"]');
        if (!delForm) return;
        const btn = document.createElement('button');
        btn.type = 'button';
        btn.className = 'btn btn-outline-danger btn-sm py-0 px-2';
        btn.textContent = 'Eliminar';
        btn.addEventListener('click', () => {
            dlgConfirm('¿Eliminar línea?', { title: 'Eliminar línea', type: 'danger' }).then(ok => {
                if (ok) eliminarLineaAjax(tr, delForm);
            });
        });
        elimTd.appendChild(btn);
    }

    function insertarLineaDetalle(json, productoBusqueda) {
        const tbody = document.querySelector('#tabla_detalle tbody');
        if (!tbody || !json?.html) return;

        tbody.querySelector('tr:not([data-linea])')?.remove();

        tbody.insertAdjacentHTML('beforeend', json.html.trim());
        const tr = tbody.lastElementChild;
        if (!tr || !tr.matches('tr[data-linea]')) return;

        const formsContainer = document.getElementById('cotiz-eliminar-lineas-forms');
        if (formsContainer && json.delete_form_html) {
            formsContainer.insertAdjacentHTML('beforeend', json.delete_form_html);
        }

        if (!desdeAdjudicadas) wireEliminarLinea(tr);

        const tituloImagen = (json.prod_item || tr.dataset.prod || '')
            + (json.prod_nombre ? ' — ' + json.prod_nombre : '');
        const imageUrl = String(json.image_url || productoBusqueda?.image_url || '').trim();
        actualizarImagenLinea(tr, imageUrl, tituloImagen);
        marcarLineasRepetidas();
        actualizarControlesOrdenVisual();
        recalcularMontoTotal();

        const wrapDetalle = document.getElementById('notaventa-tabla-detalle-wrap');
        if (wrapDetalle && json.orden) {
            wrapDetalle.dataset.maxOrden = String(json.orden);
        }
        if (json.resumen) {
            actualizarResumenLineas(json.resumen);
        }
    }

    const ordenUrl = @json(route('admin.cotizaciones.lineas.orden', $nota->nronota));
    const csrfOrden = document.querySelector('meta[name="csrf-token"]')?.content;
    let ordenEnProceso = false;

    function mostrarLoaderCotiz() {
        try {
            sessionStorage.setItem('page-loader-pending', '1');
        } catch (e) {}
        if (window.PageLoader?.show) {
            window.PageLoader.show();
        }
    }

    function ocultarLoaderCotiz() {
        if (window.PageLoader?.hide) {
            window.PageLoader.hide();
        }
    }

    function sincronizarOrdenFila(row, ordenDb) {
        const prod = row.dataset.prod;
        const ordenAnterior = parseInt(row.dataset.orden, 10);
        if (ordenAnterior === ordenDb) {
            return;
        }

        const delForm = document.querySelector(
            '.form-eliminar-linea[data-prod="' + prod + '"][data-orden="' + ordenAnterior + '"]'
        );
        if (delForm) {
            delForm.dataset.orden = String(ordenDb);
            const ordenInput = delForm.querySelector('input[name="orden"]');
            if (ordenInput) ordenInput.value = String(ordenDb);
        }

        row.dataset.orden = String(ordenDb);

        const elimTd = row.querySelector('.eliminar-cell');
        if (elimTd) elimTd.dataset.orden = String(ordenDb);

        row.querySelectorAll('.linea-orden-ir, .btn-buscar-linea-agile').forEach(btn => {
            btn.dataset.orden = String(ordenDb);
        });

        const ordenHidden = row.querySelector('input[name*="[orden]"]');
        if (ordenHidden) ordenHidden.value = String(ordenDb);

        const destinoInput = row.querySelector('.linea-orden-destino');
        if (destinoInput && document.activeElement !== destinoInput) {
            destinoInput.value = String(ordenDb);
        }
    }

    function totalLineasGrilla() {
        return document.querySelectorAll('#tabla_detalle tbody tr[data-linea]').length;
    }

    function actualizarControlesOrdenVisual() {
        const rows = Array.from(document.querySelectorAll('#tabla_detalle tbody tr[data-linea]'));
        const total = rows.length;

        rows.forEach((row, idx) => {
            const pos = idx + 1;
            const ordenNum = row.querySelector('.linea-orden-num');
            if (ordenNum) ordenNum.textContent = String(pos);

            const destinoInput = row.querySelector('.linea-orden-destino');
            if (destinoInput) {
                destinoInput.max = String(total);
                if (document.activeElement !== destinoInput) {
                    destinoInput.value = String(pos);
                }
            }
        });
    }

    function aplicarOrdenDesdeServidor(lineas) {
        if (!Array.isArray(lineas) || !lineas.length) {
            actualizarControlesOrdenVisual();
            return;
        }

        const sorted = [...lineas].sort((a, b) => a.orden - b.orden);
        const tbody = document.querySelector('#tabla_detalle tbody');
        if (!tbody) return;

        const rows = Array.from(tbody.querySelectorAll('tr[data-linea]'));
        if (sorted.length !== rows.length) {
            actualizarControlesOrdenVisual();
            return;
        }

        const used = new Set();
        const orderedRows = sorted.map((linea, idx) => {
            const prod = linea.prod_item;
            const agile = String(linea.prod_item_agile || '');
            let row = rows.find(r => ! used.has(r)
                && r.dataset.prod === prod
                && String(r.dataset.prodItemAgile || '') === agile);
            if (!row) {
                row = rows.find(r => ! used.has(r) && r.dataset.prod === prod);
            }
            if (!row) row = rows[idx];
            if (row) used.add(row);
            return row;
        }).filter(Boolean);

        orderedRows.forEach(row => tbody.appendChild(row));

        sorted.forEach((linea, idx) => {
            const row = orderedRows[idx];
            if (row) sincronizarOrdenFila(row, parseInt(linea.orden, 10));
        });

        actualizarControlesOrdenVisual();
    }

    function revertirSortable(evt) {
        const parent = evt.from;
        const item = evt.item;
        parent.removeChild(item);
        const ref = parent.children[evt.oldIndex] || null;
        parent.insertBefore(item, ref);
    }

    async function cambiarOrdenLinea(prodItem, orden, payload) {
        if (ordenEnProceso) return false;
        ordenEnProceso = true;
        mostrarLoaderCotiz();

        try {
            const res = await fetch(ordenUrl, {
                method: 'PATCH',
                headers: {
                    'Accept': 'application/json',
                    'Content-Type': 'application/json',
                    'X-CSRF-TOKEN': csrfOrden,
                    'X-Requested-With': 'XMLHttpRequest',
                },
                body: JSON.stringify({
                    prod_item: prodItem,
                    orden: orden,
                    ...payload,
                }),
            });

            const json = await res.json().catch(() => ({}));

            if (res.ok && json.ok) {
                ocultarLoaderCotiz();
                return json;
            }

            ocultarLoaderCotiz();
            dlgAlert(json.error || json.message || 'No se pudo cambiar el orden.', { title: 'Error', type: 'danger' });
            return null;
        } catch (err) {
            ocultarLoaderCotiz();
            dlgAlert('Error de conexión al cambiar el orden.', { title: 'Error', type: 'danger' });
            return null;
        } finally {
            ordenEnProceso = false;
        }
    }

    async function irAPosicionLinea(btn, inputOverride) {
        if (!btn || ordenEnProceso) return;

        const row = btn.closest('tr[data-linea]');
        const input = inputOverride || row?.querySelector('.linea-orden-destino');
        if (!row || !input) return;

        const total = totalLineasGrilla();
        const ordenNuevo = parseInt(input.value, 10);
        const ordenActual = parseInt(btn.dataset.orden || row.dataset.orden, 10);

        if (!Number.isFinite(ordenNuevo) || ordenNuevo < 1 || ordenNuevo > total) {
            dlgAlert('Indique una posición entre 1 y ' + total + '.', { title: 'Posición inválida' });
            input.focus();
            input.select();
            return;
        }

        if (ordenNuevo === ordenActual) {
            input.value = String(ordenActual);
            return;
        }

        const controles = row.querySelector('.linea-orden-controls');
        controles?.querySelectorAll('button, input').forEach(el => { el.disabled = true; });

        const result = await cambiarOrdenLinea(btn.dataset.prod, ordenActual, { orden_nuevo: ordenNuevo });

        controles?.querySelectorAll('button, input').forEach(el => { el.disabled = false; });

        if (result?.lineas) {
            aplicarOrdenDesdeServidor(result.lineas);
        }
    }

    document.getElementById('tabla_detalle')?.addEventListener('click', e => {
        const ir = e.target.closest('.linea-orden-ir');
        if (ir) {
            e.preventDefault();
            irAPosicionLinea(ir);
        }
    });

    document.getElementById('tabla_detalle')?.addEventListener('keydown', e => {
        if (e.key === 'Enter' && e.target.matches('.linea-orden-destino')) {
            e.preventDefault();
            const row = e.target.closest('tr[data-linea]');
            const ir = row?.querySelector('.linea-orden-ir');
            if (ir) irAPosicionLinea(ir, e.target);
        }
    });

    const detalleTbody = document.querySelector('#tabla_detalle tbody');
    if (detalleTbody && detalleTbody.querySelector('tr[data-linea]') && typeof Sortable !== 'undefined') {
        Sortable.create(detalleTbody, {
            animation: 160,
            handle: '.linea-drag-handle',
            draggable: 'tr[data-linea]',
            ghostClass: 'linea-sortable-ghost',
            chosenClass: 'linea-sortable-chosen',
            dragClass: 'linea-sortable-drag',
            scroll: true,
            forceAutoScrollFallback: true,
            scrollSensitivity: 60,
            scrollSpeed: 20,
            bubbleScroll: true,
            onStart: function (evt) {
                evt.item.dataset.ordenAntesDrag = evt.item.dataset.orden;
            },
            onEnd: async function (evt) {
                if (evt.oldIndex === evt.newIndex || evt.oldIndex == null || evt.newIndex == null) {
                    delete evt.item.dataset.ordenAntesDrag;
                    return;
                }

                const row = evt.item;
                const prodItem = row.dataset.prod;
                const orden = parseInt(row.dataset.ordenAntesDrag || row.dataset.orden, 10);
                const ordenNuevo = evt.newIndex + 1;
                delete row.dataset.ordenAntesDrag;

                const result = await cambiarOrdenLinea(prodItem, orden, { orden_nuevo: ordenNuevo });
                if (result) {
                    if (result.lineas) {
                        aplicarOrdenDesdeServidor(result.lineas);
                    } else {
                        actualizarControlesOrdenVisual();
                    }
                } else {
                    revertirSortable(evt);
                }
            },
        });
    }

    const lineasUrl = @json(route('admin.cotizaciones.lineas.store', $nota->nronota));
    const csrf = document.querySelector('meta[name="csrf-token"]')?.content;
    let agregandoLinea = false;

    async function agregarProducto(p, options = {}) {
        const quiet = options.quiet === true;
        const inBatch = options.inBatch === true;

        if (!p?.prod_item) {
            return false;
        }
        if (agregandoLinea && !inBatch) {
            return false;
        }

        const cantidad = document.getElementById('modal-cantidad')?.value || '1';
        const prodValor = p.prod_valor ?? 0;
        const prodValorCosto = p.prod_valor_costo ?? '';

        if (!inBatch) {
            agregandoLinea = true;
        }

        try {
            const body = new FormData();
            body.append('_token', csrf);
            body.append('prod_item', p.prod_item);
            body.append('cantidad', cantidad);
            body.append('prod_valor', prodValor);
            if (prodValorCosto !== '' && prodValorCosto != null) {
                body.append('prod_valor_costo', prodValorCosto);
            }

            const res = await fetch(lineasUrl, {
                method: 'POST',
                headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
                body,
            });

            const json = await res.json().catch(() => ({}));

            if (res.ok && json.ok) {
                insertarLineaDetalle(json, p);
                if (!quiet) {
                    ocultarLoaderCotiz();
                    try {
                        sessionStorage.removeItem('page-loader-pending');
                    } catch (e) {}
                }
                return true;
            }

            if (!quiet) {
                ocultarLoaderCotiz();
                try {
                    sessionStorage.removeItem('page-loader-pending');
                } catch (e) {}
                dlgAlert(json.error || json.message || 'No se pudo agregar la línea.', { title: 'Error', type: 'danger' });
            }
            return false;
        } catch (err) {
            if (!quiet) {
                ocultarLoaderCotiz();
                try {
                    sessionStorage.removeItem('page-loader-pending');
                } catch (e) {}
                dlgAlert('Error de conexión al agregar producto.', { title: 'Error', type: 'danger' });
            }
            return false;
        } finally {
            if (!inBatch) {
                agregandoLinea = false;
            }
        }
    }

    const buscarConfig = {
        url: @json(route('admin.productos.buscar')),
        minChars: @json((int) config('cotiz.buscar_productos_min_chars', 2)),
        limit: @json((int) config('cotiz.buscar_productos_limite', 15)),
        placeholderImg: @json(asset('images/no-image.svg')),
    };

    const modalEl = document.getElementById('modal-buscar-producto');
    const modalInput = document.getElementById('modal-buscar-input');
    const modalEstado = document.getElementById('modal-buscar-estado');
    const modalBody = document.getElementById('modal-buscar-resultados');
    const btnAbrirBuscar = document.getElementById('btn-abrir-buscar-producto');
    const btnModalBuscar = document.getElementById('btn-modal-buscar');
    const btnModalAgregarSeleccionados = document.getElementById('btn-modal-agregar-seleccionados');
    const chkSeleccionarTodos = document.getElementById('modal-buscar-seleccionar-todos');
    const productosMarcados = new Set();

    function setModalBuscarEstado(texto, cargando) {
        if (!modalEstado) return;
        modalEstado.textContent = texto;
        modalEstado.classList.toggle('cotiz-buscar-loading', !!cargando);
        modalEstado.classList.toggle('text-muted', !cargando);
    }

    function buscarLoadingHtml(texto) {
        return '<p class="small cotiz-buscar-loading mb-0">'
            + '<i class="bi bi-search me-1" aria-hidden="true"></i>'
            + (texto || 'Buscando...') + '</p>';
    }

    const bsModal = modalEl ? new bootstrap.Modal(modalEl) : null;
    let buscarAbort = null;
    let resultadosActuales = [];
    let filaActiva = -1;

    function fmtPrecio(n) {
        return '$' + Math.round(Number(n) || 0).toLocaleString('es-CL');
    }

    function buscarProductoThumbHtml(p) {
        const src = p?.image_url ? escHtml(p.image_url) : buscarConfig.placeholderImg;
        const titulo = escHtml(codigoProductoTexto(p.prod_item) + (p.prod_nombre ? ' — ' + p.prod_nombre : ''));
        const img = '<img src="' + src + '" alt="" class="cotiz-buscar-thumb" loading="eager" '
            + 'decoding="async" referrerpolicy="no-referrer" '
            + 'onerror="this.onerror=null;this.src=\'' + buscarConfig.placeholderImg + '\'">';

        if (p?.image_url) {
            return '<button type="button" class="product-image-zoom-trigger cotiz-buscar-thumb-btn" '
                + 'data-image-url="' + escHtml(p.image_url) + '" '
                + 'data-image-title="' + titulo + '" '
                + 'title="Ver imagen ampliada">' + img + '</button>';
        }

        return img;
    }

    const modalImagenEl = document.getElementById('modal-imagen-producto-cotiz');
    const modalImagenImg = document.getElementById('modal-imagen-producto-cotiz-img');
    const modalImagenTitle = document.getElementById('modal-imagen-producto-cotiz-titulo');
    const bsModalImagen = modalImagenEl ? bootstrap.Modal.getOrCreateInstance(modalImagenEl) : null;

    function abrirImagenAmpliada(trigger) {
        const url = trigger?.dataset?.imageUrl;
        if (!url || !bsModalImagen || !modalImagenImg) return;
        modalImagenImg.src = url;
        modalImagenImg.alt = trigger.dataset.imageTitle || 'Imagen producto';
        if (modalImagenTitle) {
            modalImagenTitle.textContent = trigger.dataset.imageTitle || 'Imagen producto';
        }
        if (modalImagenEl) {
            modalImagenEl.dataset.zoomAbovePopup = trigger?.closest('.cotiz-popup-overlay') ? '1' : '0';
        }
        bsModalImagen.show();
    }

    function ajustarBackdropImagenAmpliada() {
        if (!modalImagenEl || modalImagenEl.dataset.zoomAbovePopup !== '1') return;
        document.querySelectorAll('.modal-backdrop.show').forEach(backdrop => {
            backdrop.style.zIndex = '1085';
        });
    }

    function enlazarZoomImagenes(contenedor) {
        const root = contenedor || document;
        root.querySelectorAll('.product-image-zoom-trigger:not([data-zoom-bound])').forEach(trigger => {
            trigger.dataset.zoomBound = '1';
            trigger.addEventListener('click', e => {
                e.stopPropagation();
                e.preventDefault();
                abrirImagenAmpliada(trigger);
            });
        });
    }

    enlazarZoomImagenes(document.querySelector('.cotizacion-ingreso'));

    modalImagenEl?.addEventListener('shown.bs.modal', ajustarBackdropImagenAmpliada);

    modalImagenEl?.addEventListener('hidden.bs.modal', () => {
        if (modalImagenImg) {
            modalImagenImg.removeAttribute('src');
            modalImagenImg.alt = '';
        }
        if (modalImagenEl) {
            delete modalImagenEl.dataset.zoomAbovePopup;
        }
    });

    function actualizarBotonAgregarSeleccionados() {
        if (!btnModalAgregarSeleccionados) {
            return;
        }
        const n = productosMarcados.size;
        btnModalAgregarSeleccionados.disabled = n === 0 || agregandoLinea;
        btnModalAgregarSeleccionados.innerHTML = n > 0
            ? '<i class="bi bi-plus-circle"></i> Agregar seleccionados (' + n + ')'
            : '<i class="bi bi-plus-circle"></i> Agregar seleccionados';
    }

    function sincronizarSeleccionarTodos() {
        if (!chkSeleccionarTodos) {
            return;
        }
        if (!resultadosActuales.length) {
            chkSeleccionarTodos.checked = false;
            chkSeleccionarTodos.indeterminate = false;
            return;
        }
        const todos = resultadosActuales.every(p => productosMarcados.has(String(p.prod_item)));
        const alguno = resultadosActuales.some(p => productosMarcados.has(String(p.prod_item)));
        chkSeleccionarTodos.checked = todos;
        chkSeleccionarTodos.indeterminate = alguno && !todos;
    }

    function togglearProductoMarcado(prodItem, marcado) {
        const key = String(prodItem || '').trim();
        if (!key) {
            return;
        }
        if (marcado) {
            productosMarcados.add(key);
        } else {
            productosMarcados.delete(key);
        }
        actualizarBotonAgregarSeleccionados();
        sincronizarSeleccionarTodos();
    }

    function limpiarProductosMarcados() {
        productosMarcados.clear();
        actualizarBotonAgregarSeleccionados();
        sincronizarSeleccionarTodos();
    }

    async function agregarProductosSeleccionados() {
        const seleccionados = resultadosActuales.filter(p => productosMarcados.has(String(p.prod_item)));
        if (!seleccionados.length || agregandoLinea) {
            return;
        }

        agregandoLinea = true;
        actualizarBotonAgregarSeleccionados();
        mostrarLoaderCotiz();
        if (modalEstado) {
            setModalBuscarEstado('Agregando ' + seleccionados.length + ' producto(s)...', false);
        }

        let ok = 0;
        let fail = 0;
        const errores = [];

        for (const p of seleccionados) {
            const result = await agregarProducto(p, { quiet: true, inBatch: true });
            if (result) {
                ok++;
            } else {
                fail++;
                errores.push(codigoProductoTexto(p.prod_item));
            }
        }

        agregandoLinea = false;
        ocultarLoaderCotiz();
        try {
            sessionStorage.removeItem('page-loader-pending');
        } catch (e) {}

        limpiarProductosMarcados();
        modalBody?.querySelectorAll('.cotiz-buscar-check').forEach(chk => {
            chk.checked = false;
        });

        if (fail === 0) {
            bsModal?.hide();
            return;
        }

        const detalle = errores.length ? (' Productos con error: ' + errores.join(', ') + '.') : '';
        dlgAlert('Agregados: ' + ok + '. Fallidos: ' + fail + '.' + detalle, {
            title: 'Agregar productos',
            type: fail === seleccionados.length ? 'danger' : 'warning',
        });
        if (modalEstado) {
            setModalBuscarEstado('Algunos productos no se pudieron agregar. Revise la selección.', false);
        }
    }

    function marcarFilaActiva(idx) {
        filaActiva = idx;
        modalBody?.querySelectorAll('tr[data-idx]').forEach(tr => {
            tr.classList.toggle('table-active', parseInt(tr.dataset.idx, 10) === idx);
        });
    }

    function renderResultados(items, meta) {
        resultadosActuales = items || [];
        filaActiva = resultadosActuales.length ? 0 : -1;

        if (!modalBody) return;

        if (!resultadosActuales.length) {
            modalBody.innerHTML = '<tr><td colspan="6" class="text-muted text-center py-3">Sin resultados.</td></tr>';
            limpiarProductosMarcados();
            if (modalEstado) {
                setModalBuscarEstado(meta?.q
                    ? 'No se encontraron productos similares para «' + meta.q + '».'
                    : 'Escriba el texto del cliente o descripción y pulse Buscar.', false);
            }
            return;
        }

        productosMarcados.clear();
        modalBody.innerHTML = '';
        resultadosActuales.forEach((p, idx) => {
            const tr = document.createElement('tr');
            tr.dataset.idx = String(idx);
            tr.dataset.prodItem = String(p.prod_item || '');
            tr.className = 'cotiz-buscar-fila';
            tr.tabIndex = 0;
            tr.innerHTML =
                '<td class="text-center align-middle">' +
                    '<input type="checkbox" class="form-check-input cotiz-buscar-check" aria-label="Seleccionar producto">' +
                '</td>' +
                '<td class="text-center p-1">' + buscarProductoThumbHtml(p) + '</td>' +
                '<td class="align-middle"><code class="small">' + escHtml(codigoProductoTexto(p.prod_item)) + '</code></td>' +
                '<td class="align-middle small">' + (p.prod_nombre || '') + '</td>' +
                '<td class="align-middle small text-muted text-end tabular-nums">' + (p.prod_stock_real != null ? p.prod_stock_real : '—') + '</td>' +
                '<td class="align-middle text-end fw-semibold">' + fmtPrecio(p.prod_valor) + '</td>';

            const chk = tr.querySelector('.cotiz-buscar-check');
            chk?.addEventListener('change', () => togglearProductoMarcado(p.prod_item, chk.checked));
            chk?.addEventListener('click', e => e.stopPropagation());

            tr.addEventListener('click', e => {
                if (e.target.closest('.product-image-zoom-trigger') || e.target.closest('.cotiz-buscar-check')) {
                    return;
                }
                if (!chk) {
                    return;
                }
                chk.checked = !chk.checked;
                togglearProductoMarcado(p.prod_item, chk.checked);
            });
            tr.addEventListener('keydown', e => {
                if (e.key === 'Enter' || e.key === ' ') {
                    e.preventDefault();
                    if (!chk) {
                        return;
                    }
                    chk.checked = !chk.checked;
                    togglearProductoMarcado(p.prod_item, chk.checked);
                }
            });
            modalBody.appendChild(tr);
        });

        enlazarZoomImagenes(modalBody);
        actualizarBotonAgregarSeleccionados();
        sincronizarSeleccionarTodos();

        if (modalEstado && meta) {
            setModalBuscarEstado(meta.count + ' producto(s) — ordenados por similitud y precio (más barato primero).', false);
        }

        marcarFilaActiva(0);
    }

    async function ejecutarBusqueda(q) {
        if (buscarAbort) buscarAbort.abort();
        buscarAbort = new AbortController();
        const signal = buscarAbort.signal;

        if (q.length < buscarConfig.minChars) {
            renderResultados([], { q });
            if (modalEstado) {
                setModalBuscarEstado('Escriba al menos ' + buscarConfig.minChars + ' caracteres para buscar.', false);
            }
            return;
        }

        if (btnModalBuscar) btnModalBuscar.disabled = true;
        setModalBuscarEstado('Buscando...', true);

        try {
            const params = new URLSearchParams({
                q,
                limit: String(buscarConfig.limit),
            });
            const res = await fetch(buscarConfig.url + '?' + params.toString(), {
                headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
                signal,
            });
            const json = await res.json();
            renderResultados(json.data || [], json.meta || { q, count: (json.data || []).length });
        } catch (err) {
            if (err.name === 'AbortError') return;
            if (modalEstado) setModalBuscarEstado('Error al buscar. Intente de nuevo.', false);
        } finally {
            if (!signal.aborted && btnModalBuscar) {
                btnModalBuscar.disabled = false;
            }
        }
    }

    function abrirModalBuscar() {
        if (!asegurarNumeroCotizacionGuardada({
            mensajeVacio: 'Debe ingresar la cotización.',
            mensajeGuardar: 'Guarde la cotización con el botón «Guardar número» antes de agregar productos.',
        })) return;
        if (!bsModal || !modalInput) return;
        modalInput.value = '';
        limpiarProductosMarcados();
        renderResultados([], {});
        if (modalEstado) {
            setModalBuscarEstado('Escriba el texto del cliente o descripción y pulse Buscar.', false);
        }
        bsModal.show();
        setTimeout(() => {
            modalInput.focus();
        }, 200);
    }

    function lanzarBusquedaModal() {
        if (!modalInput) return;
        ejecutarBusqueda(modalInput.value.trim());
    }

    function limpiarBusquedaModal() {
        if (modalInput) {
            modalInput.value = '';
            modalInput.focus();
        }
        renderResultados([], {});
        if (modalEstado) {
            setModalBuscarEstado('Escriba el texto del cliente o descripción y pulse Buscar.', false);
        }
    }

    btnAbrirBuscar?.addEventListener('click', () => abrirModalBuscar());
    btnModalBuscar?.addEventListener('click', () => lanzarBusquedaModal());
    btnModalAgregarSeleccionados?.addEventListener('click', () => agregarProductosSeleccionados());
    document.getElementById('btn-modal-buscar-limpiar')?.addEventListener('click', limpiarBusquedaModal);

    chkSeleccionarTodos?.addEventListener('change', () => {
        const marcar = !!chkSeleccionarTodos.checked;
        resultadosActuales.forEach(p => {
            if (marcar) {
                productosMarcados.add(String(p.prod_item));
            } else {
                productosMarcados.delete(String(p.prod_item));
            }
        });
        modalBody?.querySelectorAll('.cotiz-buscar-check').forEach(chk => {
            chk.checked = marcar;
        });
        actualizarBotonAgregarSeleccionados();
        sincronizarSeleccionarTodos();
    });

    modalInput?.addEventListener('keydown', function (e) {
        if (e.key === 'Enter') {
            e.preventDefault();
            lanzarBusquedaModal();
            return;
        }

        if (!resultadosActuales.length) return;

        if (e.key === 'ArrowDown') {
            e.preventDefault();
            marcarFilaActiva(Math.min(filaActiva + 1, resultadosActuales.length - 1));
        } else if (e.key === 'ArrowUp') {
            e.preventDefault();
            marcarFilaActiva(Math.max(filaActiva - 1, 0));
        }
    });

    modalEl?.addEventListener('hidden.bs.modal', () => {
        if (buscarAbort) buscarAbort.abort();
        limpiarProductosMarcados();
    });

    function idAgileParaMercadoPublico(codigoInterno) {
        const s = String(codigoInterno || '').trim().replace(/\s/g, '');
        if (!s) return '';
        const m = s.match(/^(\d+)/);
        return (m && m[1]) ? m[1] : s;
    }

    function recolectarItemsMercadoPublico() {
        const items = [];
        document.querySelectorAll('#tabla_detalle tbody tr[data-linea]').forEach(tr => {
            const codigo = String(tr.dataset.prod || '').trim();
            const idAgileCell = String(tr.querySelector('td .linea-id-agile')?.textContent || '').trim().replace(/\s/g, '');
            const idMp = idAgileCell || idAgileParaMercadoPublico(codigo) || codigo;
            const precioNum = parseInt(tr.querySelector('.linea-prod-valor')?.value || '0', 10) || 0;
            if (codigo) {
                items.push({ idAgile: idMp, codigoInterno: codigo, valorUnitario: precioNum });
            }
        });
        return items;
    }

    function copiarTextoPortapapeles(texto) {
        if (navigator.clipboard?.writeText && window.isSecureContext) {
            return navigator.clipboard.writeText(texto);
        }
        return new Promise((resolve, reject) => {
            const ta = document.createElement('textarea');
            ta.value = texto;
            ta.setAttribute('readonly', '');
            ta.style.position = 'fixed';
            ta.style.left = '-9999px';
            document.body.appendChild(ta);
            ta.select();
            try {
                const ok = document.execCommand('copy');
                document.body.removeChild(ta);
                ok ? resolve(true) : reject(new Error('No se pudo copiar'));
            } catch (e) {
                document.body.removeChild(ta);
                reject(e);
            }
        });
    }

    document.getElementById('btnCopiarMP')?.addEventListener('click', async () => {
        const items = recolectarItemsMercadoPublico();
        if (items.length === 0) {
            dlgAlert('No hay filas válidas en la tabla de productos para copiar.', { title: 'Copiar para MP' });
            return;
        }
        const conPrecioCero = items.filter(it => it.valorUnitario <= 0);
        if (conPrecioCero.length > 0) {
            const ok = await dlgConfirm(
                'Hay ítems con precio unitario 0 o vacío. ¿Desea copiar igualmente para completar después en Mercado Público?',
                { title: 'Precio en cero', type: 'warning' },
            );
            if (!ok) return;
        }
        const payload = {
            fuente: 'cotiz',
            nronota: String(document.getElementById('nronota')?.value || '').trim(),
            cotizacion: String(document.getElementById('encargado')?.value || '').trim(),
            despacho: 0,
            items,
        };
        const jsonStr = JSON.stringify(payload, null, 2);
        const tsvLines = ['id_agile\tvalor_unitario', ...items.map(it => it.idAgile + '\t' + it.valorUnitario)];
        const bloque = '--- JSON ---\n' + jsonStr + '\n\n--- TSV ---\n' + tsvLines.join('\n');
        copiarTextoPortapapeles(bloque).then(() => {
            const msg = document.getElementById('mpCopiaMsg');
            if (msg) {
                msg.textContent = 'Copiado: ' + items.length + ' unitario(s).';
                msg.hidden = false;
                setTimeout(() => { msg.hidden = true; }, 5000);
            } else {
                dlgAlert('Copiado al portapapeles (' + items.length + ' ítems).', { title: 'Copiado', type: 'success' });
            }
        }).catch(err => {
            dlgAlert('No se pudo copiar (use HTTPS o localhost).\n' + (err?.message || ''), { title: 'Error al copiar', type: 'danger' });
        });
    });

    const resumenLineasInicial = @json($resumenLineas);
    const importarMpUrls = {
        preview: @json(route('admin.cotizaciones.importar-compra-agil.preview', $nota->nronota)),
        importar: @json(route('admin.cotizaciones.importar-compra-agil', $nota->nronota)),
        coincidencias: @json(route('admin.cotizaciones.importar-compra-agil.coincidencias', $nota->nronota)),
        limpiarAgile: @json(route('admin.cotizaciones.importar-compra-agil.limpiar-agile', $nota->nronota)),
        pdfPreview: @json(route('admin.cotizaciones.importar-pdf.preview', $nota->nronota)),
        pdfImportar: @json(route('admin.cotizaciones.importar-pdf', $nota->nronota)),
        excelPreview: @json(route('admin.cotizaciones.importar-excel.preview', $nota->nronota)),
        excelImportar: @json(route('admin.cotizaciones.importar-excel', $nota->nronota)),
        apiValidar: @json(route('admin.cotizaciones.compra-agil-api.validar', $nota->nronota)),
        apiPreview: @json(route('admin.cotizaciones.compra-agil-api.preview', $nota->nronota)),
        apiImportar: @json(route('admin.cotizaciones.compra-agil-api.importar', $nota->nronota)),
    };
    const cotizNronotaActual = @json($nota->nronota);
    const modalImportarEl = document.getElementById('modal-importar-compra-agil');
    const btnAbrirImportar = document.getElementById('btn-abrir-importar-compra-agil');
    const importarTexto = document.getElementById('importar-compra-agil-texto');
    const importarPdfInput = document.getElementById('importar-compra-agil-pdf');
    const btnImportarAnalizarPdf = document.getElementById('btn-importar-compra-agil-analizar-pdf');
    const importarExcelInput = document.getElementById('importar-compra-agil-excel');
    const importarExcelColDesc = document.getElementById('importar-compra-agil-excel-col-desc');
    const importarExcelColCant = document.getElementById('importar-compra-agil-excel-col-cant');
    const btnImportarAnalizarExcel = document.getElementById('btn-importar-compra-agil-analizar-excel');
    const importarEstado = document.getElementById('importar-compra-agil-estado');
    const importarCabecera = document.getElementById('importar-compra-agil-cabecera');
    const importarCabeceraTexto = document.getElementById('importar-compra-agil-cabecera-texto');
    const importarTablaWrap = document.getElementById('importar-compra-agil-tabla-wrap');
    const importarResultados = document.getElementById('importar-compra-agil-resultados');
    const importarResumen = document.getElementById('importar-compra-agil-resumen');
    const importarProgresoWrap = document.getElementById('importar-compra-agil-progreso-wrap');
    const importarProgresoBar = document.getElementById('importar-compra-agil-progreso');
    const importarProgresoTexto = document.getElementById('importar-compra-agil-progreso-texto');
    const importarAlerta = document.getElementById('importar-compra-agil-alerta');
    const importarAlertaTexto = document.getElementById('importar-compra-agil-alerta-texto');
    const importarConsultaPar = document.getElementById('importar-compra-agil-consulta-par');
    const importarConsultaParTexto = document.getElementById('importar-compra-agil-consulta-par-texto');
    const btnImportarAnalizar = document.getElementById('btn-importar-compra-agil-analizar');
    const btnImportarConfirmar = document.getElementById('btn-importar-compra-agil-confirmar');
    const bsModalImportar = modalImportarEl ? new bootstrap.Modal(modalImportarEl) : null;
    let importPreviewData = null;
    let importandoCompraAgil = false;
    let importCodigoApi = null;
    let importModo = 'texto';
    let importPdfFile = null;
    let importExcelFile = null;

    function escHtml(s) {
        return String(s ?? '')
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;');
    }

    const IMPORT_LOTE_SIZE = 40;
    const PREVIEW_LOTE_MIN = 5;
    const PREVIEW_LOTE_MAX = 8;

    function tamanoLoteImportar(total) {
        const n = Math.max(0, Number(total) || 0);
        if (n === 0) return IMPORT_LOTE_SIZE;
        return Math.min(IMPORT_LOTE_SIZE, n);
    }

    function tamanoLotePreview(total) {
        const n = Math.max(0, Number(total) || 0);
        if (n <= PREVIEW_LOTE_MIN) return Math.max(n, 1);
        if (n <= 40) return PREVIEW_LOTE_MIN;
        return PREVIEW_LOTE_MAX;
    }

    function construirResumenPreview(lineas) {
        let vinculados = 0;
        let conSugerencia = 0;
        (lineas || []).forEach((ln) => {
            if (ln.estado === 'vinculado') vinculados++;
            if (ln.es_sugerencia) conSugerencia++;
        });
        const total = lineas.length;
        return {
            total,
            vinculados,
            pendientes: total - vinculados,
            con_sugerencia: conSugerencia,
        };
    }

    function limpiarImportAlerta() {
        if (importarAlerta) {
            importarAlerta.classList.add('d-none');
            importarAlerta.classList.remove('alert-warning');
            importarAlerta.classList.add('alert-danger');
        }
        if (importarAlertaTexto) importarAlertaTexto.textContent = '';
        if (importarConsultaPar) importarConsultaPar.classList.add('d-none');
        if (importarConsultaParTexto) importarConsultaParTexto.textContent = '';
    }

    function mostrarImportAviso(msg) {
        if (importarAlerta && importarAlertaTexto) {
            importarAlerta.classList.remove('d-none', 'alert-danger');
            importarAlerta.classList.add('alert-warning');
            importarAlertaTexto.textContent = msg;
        }
    }

    function textoResumenLineas(detalle) {
        const total = Math.max(0, Number(detalle?.total) || 0);
        const conAgile = Math.max(0, Number(detalle?.con_agile) || 0);
        const sinAgile = Math.max(0, Number(detalle?.sin_agile) ?? (total - conAgile));
        return total + ' línea(s) en la cotización (' + conAgile + ' con ID Agile, ' + sinAgile + ' sin ID Agile).';
    }

    function actualizarResumenLineas(detalle) {
        const texto = textoResumenLineas(detalle);
        const modalTxt = document.getElementById('importar-compra-agil-detalle-actual-texto');
        const formTxt = document.getElementById('cotiz-resumen-lineas-actual');
        if (modalTxt) modalTxt.textContent = texto.replace(' en la cotización', '');
        if (formTxt) formTxt.textContent = texto;
    }

    function mensajeErrorImportJson(json, fallback) {
        if (json?.error) return json.error;
        if (json?.message && typeof json.message === 'string') return json.message;
        if (json?.errors && typeof json.errors === 'object') {
            const partes = [];
            Object.values(json.errors).forEach((msgs) => {
                (Array.isArray(msgs) ? msgs : [msgs]).forEach((m) => {
                    if (m) partes.push(String(m));
                });
            });
            if (partes.length) return partes.join(' ');
        }
        return fallback;
    }

    function mostrarImportError(msg) {
        if (importarConsultaPar) importarConsultaPar.classList.add('d-none');
        if (importarAlerta && importarAlertaTexto) {
            importarAlerta.classList.remove('d-none', 'alert-warning');
            importarAlerta.classList.add('alert-danger');
            importarAlertaTexto.textContent = msg;
        }
        if (importarEstado) importarEstado.textContent = '';
        if (btnImportarConfirmar) btnImportarConfirmar.classList.add('d-none');
    }

    function actualizarProgresoImportar(actual, total, textoExtra) {
        const totalLineas = Math.max(0, Number(total) || 0);
        const actualNum = Math.max(0, Number(actual) || 0);
        const procesadas = totalLineas > 0 ? Math.min(actualNum, totalLineas) : actualNum;
        const indeterminado = totalLineas === 0 && !!textoExtra;
        const pct = totalLineas > 0
            ? Math.round((procesadas / totalLineas) * 100)
            : (indeterminado ? 100 : (actualNum > 0 ? 100 : 0));

        if (importarProgresoBar) {
            importarProgresoBar.style.width = pct + '%';
            importarProgresoBar.setAttribute('aria-valuenow', String(pct));
            importarProgresoBar.classList.add('progress-bar-animated', 'progress-bar-striped');
            importarProgresoBar.textContent = indeterminado ? '' : (pct + '%');
        }

        if (importarProgresoTexto) {
            if (textoExtra) {
                importarProgresoTexto.textContent = textoExtra;
            } else if (totalLineas > 0) {
                importarProgresoTexto.textContent = procesadas + ' de ' + totalLineas + ' líneas (' + pct + '%)';
            } else {
                importarProgresoTexto.textContent = 'Procesando…';
            }
        }
    }

    function resetImportCompraAgilModal() {
        importPreviewData = null;
        importCodigoApi = null;
        importModo = 'texto';
        importPdfFile = null;
        importExcelFile = null;
        importandoCompraAgil = false;
        if (importarTexto) importarTexto.value = '';
        if (importarPdfInput) importarPdfInput.value = '';
        if (importarExcelInput) importarExcelInput.value = '';
        if (importarExcelColCant) importarExcelColCant.value = 'A';
        if (importarExcelColDesc) importarExcelColDesc.value = 'B';
        document.getElementById('ca-api-codigo') && (document.getElementById('ca-api-codigo').value = '');
        if (importarEstado) importarEstado.textContent = '';
        if (importarCabecera) importarCabecera.classList.add('d-none');
        if (importarCabeceraTexto) importarCabeceraTexto.textContent = '';
        if (importarTablaWrap) importarTablaWrap.classList.add('d-none');
        if (importarResultados) importarResultados.innerHTML = '';
        if (importarResumen) importarResumen.textContent = '';
        limpiarImportAlerta();
        ocultarProgresoImportar();
        if (btnImportarConfirmar) {
            btnImportarConfirmar.classList.add('d-none');
            btnImportarConfirmar.disabled = false;
        }
        if (btnImportarAnalizar) btnImportarAnalizar.disabled = false;
        if (btnImportarAnalizarPdf) btnImportarAnalizarPdf.disabled = false;
    }

    function renderImportPreview(data) {
        importPreviewData = data;

        if (!data) {
            if (importarCabecera) importarCabecera.classList.add('d-none');
            if (importarTablaWrap) importarTablaWrap.classList.add('d-none');
            if (importarResultados) importarResultados.innerHTML = '';
            if (importarResumen) importarResumen.textContent = '';
            if (btnImportarConfirmar) btnImportarConfirmar.classList.add('d-none');
            return;
        }

        if (data.error_cabecera) {
            mostrarImportError(data.error_cabecera);
        }

        const cab = data?.cabecera || {};
        const partes = [];
        if (cab.codigo_cotizacion) partes.push('Cotización: ' + cab.codigo_cotizacion);
        if (cab.empresa) partes.push('Cliente: ' + cab.empresa);
        if (cab.rutempresa) partes.push('RUT: ' + cab.rutempresa);
        if (cab.nombre) partes.push('Nombre: ' + cab.nombre);

        if (partes.length && importarCabecera && importarCabeceraTexto) {
            importarCabeceraTexto.textContent = partes.join(' · ');
            importarCabecera.classList.remove('d-none');
        } else if (importarCabecera) {
            importarCabecera.classList.add('d-none');
        }

        const lineas = data?.lineas || [];
        if (!importarResultados || !importarTablaWrap) return;

        if (lineas.length === 0) {
            importarTablaWrap.classList.add('d-none');
            importarResultados.innerHTML = '';
            if (importarResumen) importarResumen.textContent = '';
            if (btnImportarConfirmar) btnImportarConfirmar.classList.add('d-none');
            return;
        }

        importarTablaWrap.classList.remove('d-none');
        importarResultados.innerHTML = lineas.map(ln => {
            const prod = ln.producto;
            let estadoHtml;
            if (ln.estado === 'vinculado') {
                estadoHtml = '<span class="text-success">Vinculado</span>';
            } else if (ln.es_sugerencia && prod) {
                estadoHtml = '<span class="text-warning">Pendiente (sugerido)</span>';
            } else {
                estadoHtml = '<span class="text-danger">Pendiente</span>';
            }
            const prodTxt = prod
                ? escHtml(prod.prod_item) + ' — ' + escHtml(prod.prod_nombre) + (ln.es_sugerencia ? ' <span class="text-muted">(sugerencia)</span>' : '')
                : '<span class="text-muted">Buscar despu&eacute;s de importar</span>';

            return '<tr>'
                + '<td>' + escHtml(ln.id_agile) + '</td>'
                + '<td>' + escHtml(ln.descripcion) + '</td>'
                + '<td class="text-end">' + escHtml(ln.cantidad) + '</td>'
                + '<td>' + (prod ? escHtml(prod.prod_item) : '—') + '</td>'
                + '<td>' + prodTxt + '</td>'
                + '<td>' + estadoHtml + '</td>'
                + '</tr>';
        }).join('');

        const res = data?.resumen || {};
        if (importarResumen) {
            importarResumen.textContent = (res.total || 0) + ' línea(s): '
                + (res.vinculados || 0) + ' vinculada(s), '
                + (res.pendientes || 0) + ' pendiente(s).';
        }

        if (btnImportarConfirmar) {
            const puedeImportar = data?.puede_importar !== false;
            if ((res.total || 0) > 0 && puedeImportar) {
                btnImportarConfirmar.classList.remove('d-none');
            } else {
                btnImportarConfirmar.classList.add('d-none');
            }
        }
    }

    async function analizarImportCompraAgil() {
        importModo = 'texto';
        importPdfFile = null;
        importExcelFile = null;
        const texto = String(importarTexto?.value || '').trim();
        importCodigoApi = null;
        limpiarImportAlerta();
        if (!texto) {
            if (importarEstado) importarEstado.textContent = 'Pegue el texto de Compra Ágil.';
            return;
        }

        if (importarEstado) importarEstado.textContent = '';
        if (btnImportarAnalizar) btnImportarAnalizar.disabled = true;
        if (btnImportarConfirmar) btnImportarConfirmar.classList.add('d-none');
        mostrarProgresoImportar();
        actualizarProgresoImportar(0, 0, 'Verificando líneas existentes...');

        try {
            const bodyCoin = new FormData();
            bodyCoin.append('_token', csrf);
            bodyCoin.append('texto', texto);

            const resCoin = await fetch(importarMpUrls.coincidencias, {
                method: 'POST',
                headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
                body: bodyCoin,
            });
            const coin = await resCoin.json().catch(() => ({}));
            if (!resCoin.ok) {
                ocultarProgresoImportar();
                mostrarImportError(coin.error || coin.message || 'Error al verificar coincidencias.');
                return;
            }

            if ((coin.con_agile || coin.total || 0) > 0) {
                const det = coin.detalle || {};
                const okReemplazo = await dlgConfirm(
                    'La cotización tiene ' + (coin.con_agile || coin.total) + ' línea(s) con ID Agile'
                        + (det.sin_agile > 0 ? ' y ' + det.sin_agile + ' sin ID Agile' : '')
                        + '. Al analizar se eliminarán todas las líneas con ID Agile (las manuales se conservan). ¿Continuar?',
                    { title: 'Reemplazar líneas Agile', type: 'warning' },
                );
                if (!okReemplazo) {
                    ocultarProgresoImportar();
                    return;
                }

                const bodyLimp = new FormData();
                bodyLimp.append('_token', csrf);
                const resLimp = await fetch(importarMpUrls.limpiarAgile, {
                    method: 'POST',
                    headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
                    body: bodyLimp,
                });
                const limp = await resLimp.json().catch(() => ({}));
                if (!resLimp.ok) {
                    ocultarProgresoImportar();
                    mostrarImportError(limp.error || limp.message || 'No se pudieron eliminar las líneas Agile.');
                    return;
                }
                if (limp.detalle) actualizarResumenLineas(limp.detalle);
                mostrarImportAviso('Se eliminaron ' + (limp.eliminadas || 0) + ' línea(s) con ID Agile. Analizando texto nuevo...');
            }

            let todasLineas = [];
            let cabecera = null;
            let total = 0;
            let errorCabecera = null;
            let puedeImportar = true;
            let desde = 0;

            while (desde === 0 || desde < total) {
                const lote = tamanoLotePreview(total || PREVIEW_LOTE_MIN);
                const hasta = total > 0 ? Math.min(desde + lote, total) : desde + lote;

                actualizarProgresoImportar(
                    desde,
                    total || hasta,
                    total > 0 ? 'Analizando productos...' : 'Detectando productos...',
                );

                const body = new FormData();
                body.append('_token', csrf);
                body.append('texto', texto);
                body.append('desde', String(desde));
                body.append('hasta', String(hasta));

                const res = await fetch(importarMpUrls.preview, {
                    method: 'POST',
                    headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
                    body,
                });

                const json = await res.json().catch(() => ({}));
                if (!res.ok) {
                    ocultarProgresoImportar();
                    mostrarImportError(json.error || json.message || 'Error al analizar.');
                    renderImportPreview(null);
                    return;
                }

                if (json.cabecera) cabecera = json.cabecera;
                if (json.error_cabecera) {
                    errorCabecera = json.error_cabecera;
                    puedeImportar = false;
                }
                if (json.puede_importar === false) puedeImportar = false;

                total = json.total ?? total;
                todasLineas = todasLineas.concat(json.lineas || []);
                desde = json.procesadas ?? hasta;

                actualizarProgresoImportar(desde, total, 'Analizando productos...');

                if (json.completado || (total > 0 && desde >= total)) break;
                if (total === 0 && (json.lineas || []).length === 0) break;
            }

            ocultarProgresoImportar();

            const previewFinal = {
                cabecera: cabecera || {},
                lineas: todasLineas,
                resumen: construirResumenPreview(todasLineas),
                error_cabecera: errorCabecera,
                puede_importar: puedeImportar,
            };

            renderImportPreview(previewFinal);

            if (importarEstado) {
                if (errorCabecera) {
                    importarEstado.textContent = '';
                } else {
                    const n = previewFinal.resumen.total || 0;
                    importarEstado.textContent = n > 0
                        ? 'Análisis listo.'
                        : 'No se detectaron productos. Revise el texto pegado.';
                }
            }
        } catch (err) {
            ocultarProgresoImportar();
            mostrarImportError('Error de conexión.');
        } finally {
            if (btnImportarAnalizar) btnImportarAnalizar.disabled = false;
        }
    }

    async function analizarImportPdf() {
        importModo = 'pdf';
        importCodigoApi = null;
        importExcelFile = null;
        const file = importarPdfInput?.files?.[0] || null;
        if (!file) {
            if (importarEstado) importarEstado.textContent = 'Seleccione un archivo PDF o Word (.docx).';
            return;
        }
        importPdfFile = file;
        limpiarImportAlerta();
        if (importarEstado) importarEstado.textContent = '';
        if (btnImportarAnalizarPdf) btnImportarAnalizarPdf.disabled = true;
        if (btnImportarConfirmar) btnImportarConfirmar.classList.add('d-none');
        renderImportPreview(null);
        mostrarProgresoImportar();
        actualizarProgresoImportar(0, 0, 'Verificando líneas existentes...');

        try {
            const okPrep = await prepararImportAgileAntesPreview({ mantenerProgreso: true });
            if (!okPrep) return;

            mostrarProgresoImportar();
            actualizarProgresoImportar(0, 0, 'Analizando archivo...');

            let todasLineas = [];
            let cabeceraPdf = {};
            let total = 0;
            let desde = 0;

            while (desde === 0 || desde < total) {
                const lote = tamanoLotePreview(total || PREVIEW_LOTE_MIN);
                const hasta = total > 0 ? Math.min(desde + lote, total) : desde + lote;
                actualizarProgresoImportar(desde, total || hasta, 'Analizando archivo...');

                const body = new FormData();
                body.append('_token', csrf);
                body.append('pdf', importPdfFile);
                body.append('desde', String(desde));
                body.append('hasta', String(hasta));

                const res = await fetch(importarMpUrls.pdfPreview, {
                    method: 'POST',
                    headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
                    body,
                });
                const json = await res.json().catch(() => ({}));
                if (!res.ok) {
                    ocultarProgresoImportar();
                    mostrarImportError(mensajeErrorImportJson(json, 'No se pudo analizar el PDF o Word.'));
                    return;
                }

                if (json.cabecera && typeof json.cabecera === 'object') {
                    cabeceraPdf = json.cabecera;
                }

                total = json.total ?? total;
                todasLineas = todasLineas.concat(json.lineas || []);
                desde = json.procesadas ?? hasta;
                if (json.completado || (total > 0 && desde >= total)) break;
                if (total === 0 && (json.lineas || []).length === 0) break;
            }

            ocultarProgresoImportar();
            const previewFinal = {
                cabecera: cabeceraPdf || {},
                lineas: todasLineas,
                resumen: construirResumenPreview(todasLineas),
                error_cabecera: null,
                puede_importar: true,
            };
            renderImportPreview(previewFinal);

            if (importarEstado) {
                const n = previewFinal.resumen.total || 0;
                importarEstado.textContent = n > 0
                    ? 'Análisis listo (PDF / Word).'
                    : 'No se detectaron productos en el archivo.';
            }
        } catch (err) {
            ocultarProgresoImportar();
            mostrarImportError('Error de conexión.');
        } finally {
            if (btnImportarAnalizarPdf) btnImportarAnalizarPdf.disabled = false;
        }
    }

    async function analizarImportExcel() {
        importModo = 'excel';
        importCodigoApi = null;
        importPdfFile = null;
        const file = importarExcelInput?.files?.[0] || null;
        const colDesc = String(importarExcelColDesc?.value || '').trim().toUpperCase();
        const colCant = String(importarExcelColCant?.value || '').trim().toUpperCase();
        if (!file) {
            if (importarEstado) importarEstado.textContent = 'Seleccione un archivo Excel (.xlsx, .xls o .csv).';
            return;
        }
        if (!colDesc || !colCant) {
            if (importarEstado) importarEstado.textContent = 'Indique las columnas de cantidad y producto (ej. A y B).';
            return;
        }
        if (colDesc === colCant) {
            if (importarEstado) importarEstado.textContent = 'Las columnas de descripción y cantidad deben ser distintas.';
            return;
        }
        importExcelFile = file;
        limpiarImportAlerta();
        if (importarEstado) importarEstado.textContent = '';
        if (btnImportarAnalizarExcel) btnImportarAnalizarExcel.disabled = true;
        if (btnImportarConfirmar) btnImportarConfirmar.classList.add('d-none');
        renderImportPreview(null);
        mostrarProgresoImportar();
        actualizarProgresoImportar(0, 0, 'Verificando líneas existentes...');

        try {
            const okPrep = await prepararImportAgileAntesPreview({ mantenerProgreso: true });
            if (!okPrep) return;

            mostrarProgresoImportar();
            actualizarProgresoImportar(0, 0, 'Analizando Excel...');

            let todasLineas = [];
            let cabeceraExcel = {};
            let total = 0;
            let desde = 0;
            let omitidas = 0;

            while (desde === 0 || desde < total) {
                const lote = tamanoLotePreview(total || PREVIEW_LOTE_MIN);
                const hasta = total > 0 ? Math.min(desde + lote, total) : desde + lote;
                actualizarProgresoImportar(desde, total || hasta, 'Analizando Excel...');

                const body = new FormData();
                body.append('_token', csrf);
                body.append('excel', importExcelFile);
                body.append('columna_descripcion', colDesc);
                body.append('columna_cantidad', colCant);
                body.append('desde', String(desde));
                body.append('hasta', String(hasta));

                const res = await fetch(importarMpUrls.excelPreview, {
                    method: 'POST',
                    headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
                    body,
                });
                const json = await res.json().catch(() => ({}));
                if (!res.ok) {
                    ocultarProgresoImportar();
                    mostrarImportError(mensajeErrorImportJson(json, 'No se pudo analizar el Excel.'));
                    return;
                }

                if (json.cabecera && typeof json.cabecera === 'object') {
                    cabeceraExcel = json.cabecera;
                }
                if (typeof json.omitidas === 'number') {
                    omitidas = json.omitidas;
                }

                total = json.total ?? total;
                todasLineas = todasLineas.concat(json.lineas || []);
                desde = json.procesadas ?? hasta;
                if (json.completado || (total > 0 && desde >= total)) break;
                if (total === 0 && (json.lineas || []).length === 0) break;
            }

            ocultarProgresoImportar();
            const previewFinal = {
                cabecera: cabeceraExcel || {},
                lineas: todasLineas,
                resumen: construirResumenPreview(todasLineas),
                error_cabecera: null,
                puede_importar: true,
                omitidas,
            };
            renderImportPreview(previewFinal);

            if (importarEstado) {
                const n = previewFinal.resumen.total || 0;
                let msg = n > 0
                    ? 'Análisis listo (Excel).'
                    : 'No se detectaron productos en el archivo.';
                if (n > 0 && omitidas > 0) {
                    msg += ' Se omitieron ' + omitidas + ' fila(s) vacías o de título/total.';
                }
                importarEstado.textContent = msg;
            }
        } catch (err) {
            ocultarProgresoImportar();
            mostrarImportError('Error de conexión.');
        } finally {
            if (btnImportarAnalizarExcel) btnImportarAnalizarExcel.disabled = false;
        }
    }

    async function prepararImportAgileAntesPreview(opciones = {}) {
        const mantenerProgreso = opciones.mantenerProgreso === true;
        mostrarProgresoImportar();
        actualizarProgresoImportar(0, 0, 'Revisando líneas Agile en esta cotización…');

        const bodyCoin = new FormData();
        bodyCoin.append('_token', csrf);
        bodyCoin.append('texto', '');
        const resCoin = await fetch(importarMpUrls.coincidencias, {
            method: 'POST',
            headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
            body: bodyCoin,
        });
        const coin = await resCoin.json().catch(() => ({}));
        if (!resCoin.ok) {
            ocultarProgresoImportar();
            mostrarImportError(coin.error || coin.message || 'Error al verificar coincidencias.');
            return false;
        }
        if ((coin.con_agile || coin.total || 0) > 0) {
            const det = coin.detalle || {};
            ocultarProgresoImportar();
            if (importarEstado) importarEstado.textContent = '';
            const okReemplazo = await dlgConfirm(
                'La cotización tiene ' + (coin.con_agile || coin.total) + ' línea(s) con ID Agile'
                    + (det.sin_agile > 0 ? ' y ' + det.sin_agile + ' sin ID Agile' : '')
                    + '. Al importar se eliminarán las líneas con ID Agile (las manuales se conservan). ¿Continuar?',
                { title: 'Reemplazar líneas Agile', type: 'warning' },
            );
            if (!okReemplazo) {
                return false;
            }
            mostrarProgresoImportar();
            actualizarProgresoImportar(0, 0, 'Eliminando líneas Agile anteriores…');
            const bodyLimp = new FormData();
            bodyLimp.append('_token', csrf);
            const resLimp = await fetch(importarMpUrls.limpiarAgile, {
                method: 'POST',
                headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
                body: bodyLimp,
            });
            const limp = await resLimp.json().catch(() => ({}));
            if (!resLimp.ok) {
                ocultarProgresoImportar();
                mostrarImportError(limp.error || limp.message || 'No se pudieron eliminar las líneas Agile.');
                return false;
            }
            if (limp.detalle) actualizarResumenLineas(limp.detalle);
        }

        if (!mantenerProgreso) {
            ocultarProgresoImportar();
        }
        return true;
    }

    function ocultarSoloAlertaConsultaPar() {
        if (importarConsultaPar) importarConsultaPar.classList.add('d-none');
        if (importarConsultaParTexto) importarConsultaParTexto.textContent = '';
    }

    function ocultarProgresoConsultaPar() {
        ocultarSoloAlertaConsultaPar();
        ocultarProgresoImportar();
        if (importarEstado) importarEstado.textContent = '';
    }

    function mostrarProgresoConsultaPar(intento, max, mensaje) {
        limpiarImportAlerta();
        if (importarConsultaPar && importarConsultaParTexto) {
            importarConsultaPar.classList.remove('d-none');
            importarConsultaParTexto.textContent = mensaje;
        }
        if (importarProgresoWrap) {
            importarProgresoWrap.classList.remove('d-none');
            importarProgresoWrap.scrollIntoView({ block: 'nearest', behavior: 'smooth' });
        }
        const pct = Math.min(95, Math.round((intento / max) * 100));
        if (importarProgresoBar) {
            importarProgresoBar.style.width = pct + '%';
            importarProgresoBar.setAttribute('aria-valuenow', String(pct));
            importarProgresoBar.textContent = pct + '%';
            importarProgresoBar.classList.add('progress-bar-animated');
        }
        if (importarProgresoTexto) {
            importarProgresoTexto.textContent = mensaje + ' (' + intento + ' de ' + max + ')';
        }
        if (importarEstado) importarEstado.textContent = '';
    }

    let analizandoCodigoApi = false;

    async function analizarCodigoApi(codigo) {
        codigo = String(codigo || '').trim().toUpperCase();
        if (!codigo || analizandoCodigoApi) return;

        analizandoCodigoApi = true;
        importModo = 'api';
        importPdfFile = null;
        importExcelFile = null;
        importCodigoApi = codigo;
        renderImportPreview(null);
        limpiarImportAlerta();
        ocultarProgresoImportar();
        if (btnImportarConfirmar) btnImportarConfirmar.classList.add('d-none');
        if (importarEstado) importarEstado.textContent = '';

        const btnBuscar = document.getElementById('btn-ca-buscar-codigo');
        if (btnBuscar) btnBuscar.disabled = true;

        try {
            await validarEncargadoParConEspera(codigo, {
                csrf,
                onProgress: mostrarProgresoConsultaPar,
            });

            ocultarSoloAlertaConsultaPar();
            ocultarProgresoImportar();
            if (importarEstado) {
                importarEstado.textContent = 'Sitio par verificado. Cargando Mercado Público…';
            }

            if (importarProgresoWrap) importarProgresoWrap.classList.remove('d-none');
            if (importarProgresoBar) {
                importarProgresoBar.style.width = '0%';
                importarProgresoBar.setAttribute('aria-valuenow', '0');
                importarProgresoBar.textContent = '0%';
                importarProgresoBar.classList.add('progress-bar-animated');
            }
            if (importarProgresoTexto) {
                importarProgresoTexto.textContent = 'Cargando detalle Mercado Público…';
            }

            let todasLineas = [];
            let cabecera = null;
            let total = 0;
            let errorCabecera = null;
            let puedeImportar = true;
            let desde = 0;

            while (desde === 0 || desde < total) {
                const lote = tamanoLotePreview(total || PREVIEW_LOTE_MIN);
                const hasta = total > 0 ? Math.min(desde + lote, total) : desde + lote;
                actualizarProgresoImportar(desde, total || hasta, 'Analizando productos en Mercado Público…');

                const body = new FormData();
                body.append('_token', csrf);
                body.append('codigo', codigo);
                body.append('desde', String(desde));
                body.append('hasta', String(hasta));

                const res = await fetch(importarMpUrls.apiPreview, {
                    method: 'POST',
                    headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
                    body,
                });
                const json = await res.json().catch(() => ({}));
                if (!res.ok) {
                    ocultarProgresoConsultaPar();
                    mostrarImportError(json.error || 'Error al analizar.');
                    return;
                }
                if (json.cabecera) cabecera = json.cabecera;
                if (json.error_cabecera) { errorCabecera = json.error_cabecera; puedeImportar = false; }
                if (json.puede_importar === false) puedeImportar = false;
                total = json.total ?? total;
                todasLineas = todasLineas.concat(json.lineas || []);
                desde = json.procesadas ?? hasta;
                if (json.completado || (total > 0 && desde >= total)) break;
                if (total === 0 && (json.lineas || []).length === 0) break;
            }

            ocultarProgresoImportar();
            if (!errorCabecera && (todasLineas.length === 0) && (total === 0)) {
                ocultarProgresoConsultaPar();
                mostrarImportError('No se encontró la cotización «' + codigo + '» en Mercado Público.');
                return;
            }
            renderImportPreview({
                cabecera: cabecera || {},
                lineas: todasLineas,
                resumen: construirResumenPreview(todasLineas),
                error_cabecera: errorCabecera,
                puede_importar: puedeImportar,
            });
            if (importarEstado) {
                importarEstado.textContent = errorCabecera ? '' : 'Análisis listo (API).';
            }
        } catch (err) {
            ocultarProgresoConsultaPar();
            mostrarImportError(err?.message || 'No se puede importar esta cotización.');
        } finally {
            analizandoCodigoApi = false;
            if (btnBuscar) btnBuscar.disabled = false;
        }
    }

    document.getElementById('btn-ca-buscar-codigo')?.addEventListener('click', () => {
        analizarCodigoApi(document.getElementById('ca-api-codigo')?.value || '');
    });

    function mostrarProgresoImportar() {
        if (importarEstado) importarEstado.textContent = '';
        if (importarProgresoWrap) {
            importarProgresoWrap.classList.remove('d-none');
            importarProgresoWrap.scrollIntoView({ block: 'nearest', behavior: 'smooth' });
        }
        actualizarProgresoImportar(0, 0, 'Verificando líneas existentes...');
    }

    function ocultarProgresoImportar() {
        if (importarProgresoWrap) importarProgresoWrap.classList.add('d-none');
        if (importarProgresoBar) {
            importarProgresoBar.style.width = '0%';
            importarProgresoBar.setAttribute('aria-valuenow', '0');
            importarProgresoBar.textContent = '0%';
            importarProgresoBar.classList.add('progress-bar-animated');
        }
    }

    async function confirmarImportCompraAgil() {
        if (importandoCompraAgil || !importPreviewData) return;

        const usarPdf = importModo === 'pdf';
        const usarExcel = importModo === 'excel';
        if ((usarPdf || usarExcel) && !asegurarNumeroCotizacionGuardada({
            mensajeVacio: usarExcel
                ? 'Debe ingresar la cotización antes de importar el Excel.'
                : 'Debe ingresar la cotización antes de importar el PDF o Word.',
            mensajeGuardar: usarExcel
                ? 'Guarde la cotización con el botón «Guardar número» antes de importar el Excel.'
                : 'Guarde la cotización con el botón «Guardar número» antes de importar el PDF o Word.',
            titulo: 'Número de cotización',
        })) return;

        if (importPreviewData.puede_importar === false || importPreviewData.error_cabecera) {
            mostrarImportError(importPreviewData.error_cabecera || 'No se puede importar: el número de cotización ya existe.');
            return;
        }

        const texto = String(importarTexto?.value || '').trim();
        const usarApi = !!importCodigoApi;

        if (!usarApi && !usarPdf && !usarExcel && !texto) return;
        if (usarPdf && !(importPreviewData?.lineas?.length) && !importPdfFile) return;
        if (usarExcel && !(importPreviewData?.lineas?.length) && !importExcelFile) return;

        const sinMatch = importPreviewData?.resumen?.pendientes || 0;
        if (sinMatch > 0) {
            const ok = await dlgConfirm(
                'Hay ' + sinMatch + ' línea(s) pendientes de vincular. Se importarán todas; use Buscar en cada fila para asignar el producto del maestro. ¿Continuar?',
                { title: 'Importar con pendientes', type: 'warning' },
            );
            if (!ok) return;
        }

        if (usarApi || usarPdf || usarExcel) {
            const okAgile = await prepararImportAgileAntesPreview();
            if (!okAgile) return;
        }

        importandoCompraAgil = true;
        if (btnImportarConfirmar) btnImportarConfirmar.disabled = true;
        if (btnImportarAnalizar) btnImportarAnalizar.disabled = true;
        if (btnImportarAnalizarPdf) btnImportarAnalizarPdf.disabled = true;
        if (btnImportarAnalizarExcel) btnImportarAnalizarExcel.disabled = true;
        mostrarProgresoImportar();

        const total = importPreviewData?.resumen?.total || 0;

        try {
            if (usarApi) {
                const lote = tamanoLoteImportar(total);
                for (let desde = 0; desde < total; desde += lote) {
                    const hasta = Math.min(desde + lote, total);
                    actualizarProgresoImportar(desde, total);
                    const body = new FormData();
                    body.append('_token', csrf);
                    body.append('codigo', importCodigoApi);
                    body.append('desde', String(desde));
                    body.append('hasta', String(hasta));
                    const res = await fetch(importarMpUrls.apiImportar, {
                        method: 'POST',
                        headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
                        body,
                    });
                    const json = await res.json().catch(() => ({}));
                    if (!res.ok) {
                        ocultarProgresoImportar();
                        mostrarImportError(mensajeErrorImportJson(json, 'No se pudo importar.'));
                        return;
                    }
                }
            } else if (usarPdf || usarExcel) {
                const lineasPreview = (importPreviewData.lineas || []).map((l) => ({
                    id_agile: l.id_agile,
                    descripcion: l.descripcion,
                    cantidad: l.cantidad,
                    categoria: l.categoria || '',
                    estado: l.estado || '',
                    es_sugerencia: !!l.es_sugerencia,
                    producto: l.producto || null,
                }));
                const cabeceraPreview = importPreviewData.cabecera || {};
                const importUrl = usarExcel ? importarMpUrls.excelImportar : importarMpUrls.pdfImportar;
                const errorSinLineas = usarExcel
                    ? 'No hay líneas del análisis para importar. Analice el Excel de nuevo.'
                    : 'No hay líneas del análisis para importar. Analice el PDF o Word de nuevo.';

                if (lineasPreview.length === 0) {
                    ocultarProgresoImportar();
                    mostrarImportError(errorSinLineas);
                    return;
                }

                const lote = tamanoLoteImportar(total || lineasPreview.length);
                const totalLineas = lineasPreview.length;
                for (let desde = 0; desde < totalLineas; desde += lote) {
                    const hasta = Math.min(desde + lote, totalLineas);
                    actualizarProgresoImportar(desde, totalLineas, desde === 0 ? 'Importando líneas al detalle...' : null);

                    const body = new FormData();
                    body.append('_token', csrf);
                    body.append('desde', String(desde));
                    body.append('hasta', String(hasta));
                    body.append('lineas_json', JSON.stringify(lineasPreview));
                    if (desde === 0) {
                        body.append('cabecera_json', JSON.stringify(cabeceraPreview));
                    }

                    const res = await fetch(importUrl, {
                        method: 'POST',
                        headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
                        body,
                    });
                    const json = await res.json().catch(() => ({}));
                    if (!res.ok) {
                        ocultarProgresoImportar();
                        mostrarImportError(mensajeErrorImportJson(json, 'No se pudo importar.'));
                        return;
                    }

                    actualizarProgresoImportar(hasta, totalLineas);
                }
            } else if (total === 0) {
                const body = new FormData();
                body.append('_token', csrf);
                body.append('texto', texto);
                body.append('desde', '0');
                body.append('hasta', '0');

                const res = await fetch(importarMpUrls.importar, {
                    method: 'POST',
                    headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
                    body,
                });
                const json = await res.json().catch(() => ({}));
                if (!res.ok) {
                    ocultarProgresoImportar();
                    mostrarImportError(mensajeErrorImportJson(json, 'No se pudo importar.'));
                    return;
                }
            } else {
                const lote = tamanoLoteImportar(total);
                for (let desde = 0; desde < total; desde += lote) {
                    const hasta = Math.min(desde + lote, total);
                    const textoProgreso = desde === 0
                        ? 'Actualizando cabecera e importando líneas...'
                        : null;
                    actualizarProgresoImportar(desde, total, textoProgreso);

                    const body = new FormData();
                    body.append('_token', csrf);
                    body.append('texto', texto);
                    body.append('desde', String(desde));
                    body.append('hasta', String(hasta));

                    const res = await fetch(importarMpUrls.importar, {
                        method: 'POST',
                        headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
                        body,
                    });

                    const json = await res.json().catch(() => ({}));
                    if (!res.ok) {
                        ocultarProgresoImportar();
                        mostrarImportError(mensajeErrorImportJson(json, 'No se pudo importar.'));
                        return;
                    }

                    actualizarProgresoImportar(hasta, total);
                }
            }

            actualizarProgresoImportar(total, total, 'Importación lista. Actualizando pantalla...');
            if (importarProgresoBar) {
                importarProgresoBar.classList.remove('progress-bar-animated');
            }
            mostrarLoaderCotiz();
            window.location.reload();
        } catch (err) {
            ocultarProgresoImportar();
            mostrarImportError('Error de conexión.');
        } finally {
            importandoCompraAgil = false;
            if (btnImportarConfirmar) btnImportarConfirmar.disabled = false;
            if (btnImportarAnalizar) btnImportarAnalizar.disabled = false;
            if (btnImportarAnalizarPdf) btnImportarAnalizarPdf.disabled = false;
            if (btnImportarAnalizarExcel) btnImportarAnalizarExcel.disabled = false;
        }
    }

    btnAbrirImportar?.addEventListener('click', () => {
        resetImportCompraAgilModal();
        actualizarResumenLineas(resumenLineasInicial);
        bsModalImportar?.show();
        setTimeout(() => document.getElementById('ca-api-codigo')?.focus(), 200);
    });

    btnImportarAnalizar?.addEventListener('click', () => analizarImportCompraAgil());
    btnImportarAnalizarPdf?.addEventListener('click', () => analizarImportPdf());
    btnImportarAnalizarExcel?.addEventListener('click', () => analizarImportExcel());
    btnImportarConfirmar?.addEventListener('click', () => confirmarImportCompraAgil());

    modalImportarEl?.addEventListener('hidden.bs.modal', () => {
        resetImportCompraAgilModal();
    });

    const vincularAgileUrl = @json(route('admin.cotizaciones.lineas.vincular-agile', $nota->nronota));
    const popupVincularEl = document.getElementById('popupVincularAgile');
    const popupVincularDesc = document.getElementById('popupVincularDescAgile');
    const popupVincularBusqueda = document.getElementById('popupVincularBusqueda');
    const popupVincularResultados = document.getElementById('popupVincularResultados');
    let vincularFilaActual = null;
    let vincularOrdenActual = null;
    let vincularAgileIdActual = null;
    let vincularResultadosActuales = [];

    function formatMoneyCotiz(n) {
        return Math.round(Number(n) || 0).toLocaleString('es-CL');
    }

    function abrirPopupVincularAgile(btn) {
        const fila = btn.closest('tr[data-linea]');
        vincularFilaActual = fila?.dataset.linea ?? btn.dataset.fila ?? null;
        vincularOrdenActual = fila?.dataset.orden ?? btn.dataset.orden ?? null;
        vincularAgileIdActual = btn.dataset.prodItemAgile || '';
        const desc = btn.dataset.descripcionAgile || '';
        if (popupVincularDesc) popupVincularDesc.textContent = desc;
        if (popupVincularBusqueda) popupVincularBusqueda.value = desc;
        if (popupVincularResultados) popupVincularResultados.innerHTML = '';
        if (popupVincularEl) popupVincularEl.style.display = 'flex';
        buscarProductosVincularPopup();
    }

    function cerrarPopupVincularAgile() {
        if (popupVincularEl) popupVincularEl.style.display = 'none';
        vincularFilaActual = null;
        vincularOrdenActual = null;
        vincularAgileIdActual = null;
        vincularResultadosActuales = [];
    }

    function encontrarFilaVincular(filaIdx, orden, agileId) {
        if (filaIdx != null && filaIdx !== '') {
            const porIndice = document.querySelector('#tabla_detalle tbody tr[data-linea="' + filaIdx + '"]');
            if (porIndice) return porIndice;
        }

        return Array.from(document.querySelectorAll('#tabla_detalle tbody tr[data-prod-item-agile]'))
            .find(tr => String(tr.dataset.orden) === String(orden)
                && String(tr.dataset.prodItemAgile || '') === String(agileId)) || null;
    }

    function actualizarImagenLinea(tr, imageUrl, titulo) {
        const cell = tr.querySelector('.linea-imagen-cell');
        if (!cell) return;

        if (imageUrl) {
            const partesTitulo = String(titulo || '').split(' — ');
            cell.innerHTML = buscarProductoThumbHtml({
                image_url: imageUrl,
                prod_item: partesTitulo[0] || tr.dataset.prod || '',
                prod_nombre: partesTitulo.slice(1).join(' — ') || '',
            });
        } else {
            cell.innerHTML = buscarProductoThumbHtml({});
        }

        enlazarZoomImagenes(cell);
    }

    function buscarProductosVincularPopup() {
        const q = popupVincularBusqueda?.value?.trim() || '';
        const cont = popupVincularResultados;
        if (!cont) return;
        if (q.length < buscarConfig.minChars) {
            cont.innerHTML = '<p class="text-muted small">Escriba al menos ' + buscarConfig.minChars + ' caracteres.</p>';
            return;
        }

        cont.innerHTML = buscarLoadingHtml('Buscando...');
        fetch(buscarConfig.url + '?q=' + encodeURIComponent(q) + '&limit=' + buscarConfig.limit, {
            headers: { Accept: 'application/json' },
        })
            .then(r => r.json())
            .then(data => {
                const items = data.data || [];
                vincularResultadosActuales = items;
                if (!items.length) {
                    cont.innerHTML = '<p class="text-muted small">Sin resultados.</p>';
                    return;
                }
                let html = '<table class="table table-sm table-hover mb-0 cotiz-buscar-tabla"><thead><tr>'
                    + '<th style="width:80px"></th>'
                    + '<th>Código</th><th>Nombre</th>'
                    + '<th class="text-end" style="width:70px">Stock</th>'
                    + '<th>Costo</th><th>Venta</th><th></th>'
                    + '</tr></thead><tbody>';
                items.forEach((p, idx) => {
                    html += '<tr>'
                        + '<td class="text-center p-1">' + buscarProductoThumbHtml(p) + '</td>'
                        + '<td>' + escHtml(p.prod_item) + '</td>'
                        + '<td>' + escHtml(p.prod_nombre) + '</td>'
                        + '<td class="text-end small text-muted tabular-nums">' + (p.prod_stock_real != null ? p.prod_stock_real : '—') + '</td>'
                        + '<td>' + formatMoneyCotiz(p.prod_valor_costo) + '</td>'
                        + '<td>' + formatMoneyCotiz(p.prod_valor) + '</td>'
                        + '<td><button type="button" class="btn btn-sm btn-primary btn-seleccionar-vinculo" data-vinculo-idx="' + idx + '">Seleccionar</button></td>'
                        + '</tr>';
                });
                html += '</tbody></table>';
                cont.innerHTML = html;
                enlazarZoomImagenes(cont);
                cont.querySelectorAll('.btn-seleccionar-vinculo').forEach(b => {
                    b.addEventListener('click', e => {
                        e.stopPropagation();
                        const p = vincularResultadosActuales[parseInt(b.dataset.vinculoIdx, 10)];
                        if (!p) return;
                        seleccionarVinculoAgile(
                            p.prod_item,
                            parseInt(p.prod_valor_costo, 10) || 0,
                            parseInt(p.prod_valor, 10) || 0,
                            p.prod_nombre || '',
                            b,
                        );
                    });
                });
            })
            .catch(() => {
                cont.innerHTML = '<p class="text-danger small">Error al buscar.</p>';
            });
    }

    function actualizarFilaVinculada(filaIdx, orden, agileId, linea) {
        const tr = encontrarFilaVincular(filaIdx, orden, agileId);
        if (!tr || !linea) return false;

        const codigoRaw = String(linea.prod_item || '').trim();
        const codigoMostrar = codigoProductoTexto(linea.prod_item);
        const prodAnterior = tr.dataset.prod || '';
        const tituloImagen = codigoMostrar + (linea.prod_nombre ? ' — ' + linea.prod_nombre : '');

        const delForm = document.querySelector('.form-eliminar-linea[data-orden="' + tr.dataset.orden + '"][data-prod="' + prodAnterior + '"]')
            || document.querySelector('.form-eliminar-linea[data-orden="' + orden + '"][data-prod="' + prodAnterior + '"]');
        if (delForm) {
            delForm.dataset.prod = codigoRaw;
            const delProdInput = delForm.querySelector('input[name="prod_item"]');
            if (delProdInput) delProdInput.value = codigoRaw;
        }

        tr.dataset.prod = codigoRaw;
        tr.classList.remove('linea-pendiente-vinculo');

        const codigoSpan = tr.querySelector('.linea-codigo-interno');
        if (codigoSpan) {
            codigoSpan.textContent = codigoMostrar;
            codigoSpan.classList.remove('text-warning', 'fw-semibold');
        }

        const hiddenProd = tr.querySelector('input[name*="[prod_item]"]');
        if (hiddenProd) hiddenProd.value = codigoRaw;

        const nombreCell = tr.querySelector('.linea-prod-nombre');
        if (nombreCell) {
            nombreCell.textContent = linea.prod_nombre || codigoMostrar;
            nombreCell.classList.remove('text-warning-emphasis');
        }

        actualizarImagenLinea(tr, linea.image_url || '', tituloImagen);

        const descAgileTd = tr.querySelector('td .linea-desc-agile')?.closest('td')
            || tr.querySelector('.linea-id-agile')?.closest('tr')?.children[5];
        if (descAgileTd && linea.prod_descripcion_agile) {
            descAgileTd.innerHTML = '<span class="nv-fill linea-desc-agile small">'
                + escHtml(linea.prod_descripcion_agile) + '</span>';
        }

        const buscarBtn = tr.querySelector('.btn-buscar-linea-agile');
        if (buscarBtn && linea.prod_descripcion_agile) {
            buscarBtn.dataset.descripcionAgile = linea.prod_descripcion_agile;
        }

        const costoInput = tr.querySelector('.nv-precio-costo-sololectura');
        if (costoInput) costoInput.value = linea.prod_valor_costo ?? 0;

        const ventaInput = tr.querySelector('.linea-prod-valor');
        if (ventaInput) ventaInput.value = linea.prod_valor ?? 0;

        const fechaSpan = tr.querySelector('td:nth-child(8) .nv-fill');
        if (fechaSpan && linea.prod_valor_fecha_fmt) {
            fechaSpan.textContent = linea.prod_valor_fecha_fmt;
            fechaSpan.classList.toggle('fecha-precio-antigua', !!linea.prod_valor_fecha_antigua);
        }

        const cantidad = parseInt(tr.querySelector('.linea-cantidad')?.value || '1', 10) || 1;
        const totalTd = tr.querySelector('.linea-total');
        if (totalTd) {
            const subtotal = linea.subtotal ?? ((linea.prod_valor || 0) * cantidad);
            totalTd.textContent = '$' + formatMoneyCotiz(subtotal);
        }

        tr.querySelectorAll('[data-prod]').forEach(el => {
            if (el.classList.contains('eliminar-cell')) {
                el.dataset.prod = codigoRaw;
            }
        });

        recalcularMontoTotal();
        marcarLineasRepetidas();

        return true;
    }

    async function seleccionarVinculoAgile(codigo, costo, venta, nombre, btnEl) {
        if (vincularOrdenActual == null || !vincularAgileIdActual) return;

        const filaIdx = vincularFilaActual;
        const orden = parseInt(vincularOrdenActual, 10);
        const agileId = vincularAgileIdActual;
        const filaOrden = document.querySelector('#tabla_detalle tbody tr[data-linea="' + filaIdx + '"]')?.dataset.orden;
        const ordenEnvio = filaOrden ? parseInt(filaOrden, 10) : orden;

        if (btnEl) {
            btnEl.disabled = true;
            btnEl.textContent = 'Vinculando...';
            btnEl.closest('tr')?.classList.add('table-active');
            popupVincularResultados?.querySelectorAll('.btn-seleccionar-vinculo').forEach(b => {
                if (b !== btnEl) b.disabled = true;
            });
        }

        try {
            const res = await fetch(vincularAgileUrl, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'Accept': 'application/json',
                    'X-CSRF-TOKEN': csrf,
                    'X-Requested-With': 'XMLHttpRequest',
                },
                body: JSON.stringify({
                    orden: ordenEnvio,
                    prod_item_agile: agileId,
                    prod_item: codigo,
                    prod_valor: venta,
                }),
            });
            const json = await res.json().catch(() => ({}));
            if (!res.ok) {
                if (btnEl) {
                    btnEl.disabled = false;
                    btnEl.textContent = 'Seleccionar';
                    btnEl.closest('tr')?.classList.remove('table-active');
                }
                popupVincularResultados?.querySelectorAll('.btn-seleccionar-vinculo').forEach(b => { b.disabled = false; });
                dlgAlert(json.error || 'No se pudo vincular el producto.', { title: 'Error', type: 'danger' });
                return;
            }

            const linea = json.linea || {
                prod_item: codigo,
                prod_nombre: nombre,
                prod_valor: venta,
                prod_valor_costo: costo,
                subtotal: venta * (parseInt(document.querySelector('#tabla_detalle tbody tr[data-linea="' + filaIdx + '"] .linea-cantidad')?.value || '1', 10) || 1),
            };

            const actualizado = actualizarFilaVinculada(filaIdx, ordenEnvio, agileId, linea);
            cerrarPopupVincularAgile();

            if (!actualizado) {
                dlgAlert('Producto vinculado, pero no se pudo refrescar la fila. Recargue la página.', { title: 'Aviso', type: 'warning' });
            }
        } catch (err) {
            if (btnEl) {
                btnEl.disabled = false;
                btnEl.textContent = 'Seleccionar';
                btnEl.closest('tr')?.classList.remove('table-active');
            }
            popupVincularResultados?.querySelectorAll('.btn-seleccionar-vinculo').forEach(b => { b.disabled = false; });
            dlgAlert('Error de conexión al vincular producto.', { title: 'Error', type: 'danger' });
        }
    }

    document.getElementById('tabla_detalle')?.addEventListener('click', e => {
        const btn = e.target.closest('.btn-buscar-linea-agile');
        if (btn) abrirPopupVincularAgile(btn);
    });
    document.getElementById('cerrarPopupVincularAgile')?.addEventListener('click', cerrarPopupVincularAgile);
    document.getElementById('btnPopupVincularBuscar')?.addEventListener('click', buscarProductosVincularPopup);
    document.getElementById('btnPopupVincularLimpiar')?.addEventListener('click', () => {
        if (popupVincularBusqueda) {
            popupVincularBusqueda.value = '';
            popupVincularBusqueda.focus();
        }
        if (popupVincularResultados) {
            popupVincularResultados.innerHTML = '';
        }
    });
    popupVincularBusqueda?.addEventListener('keydown', e => {
        if (e.key === 'Enter') {
            e.preventDefault();
            buscarProductosVincularPopup();
        }
    });
    popupVincularEl?.addEventListener('click', e => {
        if (e.target === popupVincularEl) cerrarPopupVincularAgile();
    });

    @if($lineas->contains(fn ($row) => $row['repetidos'] > 1))
        dlgAlert('Existen productos que se repiten, estos están marcados con rojo, favor revisar si corresponde', {
            title: 'Productos repetidos',
            type: 'warning',
        });
    @endif

    if (abrirImportarAlInicio && bsModalImportar) {
        resetImportCompraAgilModal();
        actualizarResumenLineas(resumenLineasInicial);
        bsModalImportar.show();
        setTimeout(() => document.getElementById('ca-api-codigo')?.focus(), 250);
    }
})();
</script>
@endpush
