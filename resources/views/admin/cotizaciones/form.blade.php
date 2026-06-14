@extends('layouts.admin')

@section('title', 'Cotización '.$nota->nronota)

@push('head')
<link href="{{ asset('css/cotizacion-form.css') }}?v=mp-buscar-42" rel="stylesheet">
@endpush

@section('content')
@php
    $factorValor = (float) ($nota->factor_precio_venta ?? config('cotiz.factor_precio_venta'));
    $factorMostrado = number_format($factorValor, 2, ',', '');
    $factorInput = old('factor_precio_venta', $factorMostrado);
@endphp

<div class="cotizacion-ingreso">
    <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-2">
        <h1 class="h5 mb-0">Ingreso Cotizaci&oacute;n #{{ $nota->nronota }}</h1>
        <a href="{{ route('admin.cotizaciones.index') }}" class="btn btn-outline-secondary btn-sm">&larr; Listado</a>
    </div>

    @if($requiereNumeroCotizacion)
        <div class="alert alert-danger py-2 mb-2" role="alert">
            Debe ingresar el <strong>n&uacute;mero de cotizaci&oacute;n</strong> y pulsar <strong>Guardar n&uacute;mero</strong> antes de agregar productos manualmente.
            Puede usar <strong>Importar desde Compra &Aacute;gil</strong> para cargar cabecera y l&iacute;neas desde Mercado P&uacute;blico.
        </div>
    @endif

    @if($hayPrecioAntiguo)
        <div class="alert alert-warning py-2 alert-precio-antiguo mb-2">
            Hay productos con precio de actualizaci&oacute;n anterior a {{ $umbralPrecioMeses }} mes(es) (fechas en rojo).
        </div>
    @endif

    <form method="post" action="{{ route('admin.cotizaciones.update', $nota->nronota) }}" id="form-cotizacion">
        @csrf
        @method('PUT')

        <fieldset class="cotiz-cabecera">
            <table id="tabla_datos1">
                <tr>
                    <th>Cliente</th>
                    <td><input type="text" name="empresa" id="empresa" maxlength="100" value="{{ old('empresa', $nota->empresa) }}"></td>
                    <th>
                        Cotizaci&oacute;n
                        @if($requiereNumeroCotizacion)
                            <span class="text-danger"> *</span>
                        @endif
                    </th>
                    <td>
                        <input
                            type="text"
                            name="encargado"
                            id="encargado"
                            maxlength="100"
                            value="{{ old('encargado', $nota->encargado) }}"
                            required
                            @class([
                                'cotiz-campo-numero-cotiz' => $requiereNumeroCotizacion || $errors->has('encargado'),
                                'is-invalid' => $errors->has('encargado'),
                            ])
                            @if($requiereNumeroCotizacion) autofocus @endif
                            placeholder="{{ $requiereNumeroCotizacion ? 'Nº cotización obligatorio' : '' }}"
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

        <div class="cotiz-importar-mp mb-2 d-flex flex-wrap gap-2 align-items-center">
            <button type="button" class="btn btn-outline-primary btn-sm" id="btn-abrir-importar-compra-agil">
                <i class="bi bi-clipboard-data"></i> Importar desde Compra &Aacute;gil
            </button>
            <span class="small text-muted" id="cotiz-resumen-lineas-actual">
                {{ $resumenLineas['total'] }} l&iacute;nea(s) en la cotizaci&oacute;n
                ({{ $resumenLineas['con_agile'] }} con ID Agile, {{ $resumenLineas['sin_agile'] }} sin ID Agile).
            </span>
            <span class="small text-muted">Pegue texto de Mercado P&uacute;blico para cargar cabecera y l&iacute;neas (vincule productos con Buscar en cada fila).</span>
        </div>

        <div @class(['cotiz-contenido-detalle', 'cotiz-contenido-bloqueado' => $requiereNumeroCotizacion])>
            <div id="notaventa-bloque-factor" class="cotiz-cabecera-factor mb-2">
                <span class="d-inline-flex flex-wrap align-items-center column-gap-3 row-gap-2">
                    <span class="text-nowrap"><strong>&Uacute;ltimo factor guardado:</strong> <span id="factor_precio_venta_mostrado">{{ $factorMostrado }}</span></span>
                    <label for="factor_precio_venta" class="mb-0 text-nowrap"><strong>Factor Aumento Precio Venta:</strong></label>
                    <input type="text" name="factor_precio_venta" id="factor_precio_venta" size="7" maxlength="7" inputmode="decimal" autocomplete="off" title="Hasta 2 decimales (ej.: 1,30)" value="{{ $factorInput }}" @class(['is-invalid' => $errors->has('factor_precio_venta')])>
                    @error('factor_precio_venta')
                        <span class="text-danger small">{{ $message }}</span>
                    @enderror
                    <button type="submit" name="accion" value="aplicar_factor" class="btn btn-outline-primary btn-sm" id="btnFactorAumentoAceptar">Aplicar Nuevo Factor</button>
                </span>
            </div>

        <div class="cotiz-agregar mb-2">
            <button type="button" class="btn btn-success btn-sm" id="btn-abrir-buscar-producto">
                <i class="bi bi-plus-circle"></i> Agregar producto
            </button>
        </div>

        <div id="notaventa-tabla-detalle-wrap" data-max-orden="{{ $lineas->count() }}">
            <table id="tabla_detalle" class="table table-sm">
                <thead>
                    <tr>
                        <th class="linea-drag-col" title="Arrastrar para reordenar"></th>
                        <th>Imagen</th>
                        <th>C&oacute;digo</th>
                        <th>Cod. Softland</th>
                        <th>ID Agile</th>
                        <th>Descripci&oacute;n Agile (MP)</th>
                        <th>Descripci&oacute;n maestro</th>
                        <th>Fecha<br>act.&nbsp;precio</th>
                        <th>Precio Costo</th>
                        <th>Precio Unitario</th>
                        <th>Cantidad</th>
                        <th>Total</th>
                        <th>Orden</th>
                        <th>Eliminar</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse($lineas as $idx => $row)
                        @php $linea = $row['linea']; @endphp
                        <tr
                            @class([
                                'linea-repetida' => $row['repetidos'] > 1,
                                'linea-pendiente-vinculo' => $row['pendiente_vinculo'],
                            ])
                            data-linea="{{ $idx }}"
                            data-prod="{{ $linea->prod_item }}"
                            data-orden="{{ $linea->orden }}"
                            @if($row['prod_item_agile'] !== '') data-prod-item-agile="{{ $row['prod_item_agile'] }}" @endif
                        >
                            <td class="text-center linea-drag-cell">
                                <span class="linea-drag-handle" title="Arrastrar para reordenar" aria-label="Arrastrar fila">
                                    <svg class="linea-drag-grip" width="14" height="20" viewBox="0 0 14 20" aria-hidden="true" focusable="false">
                                        <circle cx="4" cy="4" r="2"/>
                                        <circle cx="10" cy="4" r="2"/>
                                        <circle cx="4" cy="10" r="2"/>
                                        <circle cx="10" cy="10" r="2"/>
                                        <circle cx="4" cy="16" r="2"/>
                                        <circle cx="10" cy="16" r="2"/>
                                    </svg>
                                </span>
                            </td>
                            <td class="linea-imagen-cell" onclick="event.stopPropagation();">
                                <x-product-image
                                    :maeprod="$linea->producto"
                                    :alt="$row['prod_nombre']"
                                    variant="admin-thumb"
                                    wrapperClass="imagen"
                                />
                            </td>
                            <td>
                                <div class="d-flex flex-wrap gap-1 align-items-center">
                                    <span class="linea-codigo-interno @if($row['pendiente_vinculo']) text-warning fw-semibold @endif">{{ $linea->prod_item }}</span>
                                    @if($row['prod_item_agile'] !== '')
                                        <button
                                            type="button"
                                            class="btn btn-outline-primary btn-sm btn-buscar-linea-agile text-nowrap flex-shrink-0"
                                            data-fila="{{ $idx }}"
                                            data-orden="{{ $linea->orden }}"
                                            data-prod-item-agile="{{ $row['prod_item_agile'] }}"
                                            data-descripcion-agile="{{ $row['prod_descripcion_agile'] }}"
                                            title="Buscar o cambiar producto del maestro"
                                        >Buscar</button>
                                    @endif
                                </div>
                                <input type="hidden" name="lineas[{{ $idx }}][prod_item]" value="{{ $linea->prod_item }}">
                                <input type="hidden" name="lineas[{{ $idx }}][orden]" value="{{ $linea->orden }}">
                            </td>
                            <td>
                                <input type="text" name="lineas[{{ $idx }}][prod_item_softland]" maxlength="20" value="{{ old('lineas.'.$idx.'.prod_item_softland', $row['prod_item_softland']) }}" title="C&oacute;digo Softland">
                            </td>
                            <td><span class="nv-fill linea-id-agile">{{ $row['prod_item_agile'] }}</span></td>
                            <td>
                                @if($row['prod_item_agile'] !== '' && $row['prod_descripcion_agile'] !== '')
                                    <span class="nv-fill linea-desc-agile small">{{ $row['prod_descripcion_agile'] }}</span>
                                @else
                                    <span class="text-muted">—</span>
                                @endif
                            </td>
                            <td>
                                @if($row['pendiente_vinculo'])
                                    <span class="nv-fill linea-prod-nombre text-warning-emphasis">Sin vincular</span>
                                @else
                                    <span class="nv-fill linea-prod-nombre">{{ $row['prod_nombre'] }}</span>
                                @endif
                            </td>
                            <td>
                                <span @class(['nv-fill', 'fecha-precio-antigua' => $row['prod_valor_fecha_antigua']])>{{ $row['prod_valor_fecha'] }}</span>
                            </td>
                            <td>
                                <input type="number" name="lineas[{{ $idx }}][prod_valor_costo]" class="nv-precio-costo-sololectura" value="{{ old('lineas.'.$idx.'.prod_valor_costo', $linea->prod_valor_costo) }}" readonly tabindex="-1" title="Precio costo (solo lectura)">
                            </td>
                            <td>
                                <input type="number" name="lineas[{{ $idx }}][prod_valor]" class="linea-prod-valor" min="0" value="{{ old('lineas.'.$idx.'.prod_valor', $linea->prod_valor) }}" title="Precio unitario">
                            </td>
                            <td>
                                <input type="number" name="lineas[{{ $idx }}][cantidad]" class="linea-cantidad" min="1" value="{{ old('lineas.'.$idx.'.cantidad', $linea->cantidad) }}">
                            </td>
                            <td class="linea-total text-end">${{ number_format($row['total'], 0, ',', '.') }}</td>
                            <td class="text-center linea-orden-cell">
                                <div class="linea-orden-controls">
                                    <span class="linea-orden-num">{{ $linea->orden }}</span>
                                    <div class="linea-orden-buttons">
                                        <button
                                            type="button"
                                            class="btn btn-outline-secondary btn-sm linea-orden-subir"
                                            data-prod="{{ $linea->prod_item }}"
                                            data-orden="{{ $linea->orden }}"
                                            title="Subir"
                                            @disabled($loop->first)
                                        ><i class="bi bi-chevron-up"></i></button>
                                        <button
                                            type="button"
                                            class="btn btn-outline-secondary btn-sm linea-orden-bajar"
                                            data-prod="{{ $linea->prod_item }}"
                                            data-orden="{{ $linea->orden }}"
                                            title="Bajar"
                                            @disabled($loop->last)
                                        ><i class="bi bi-chevron-down"></i></button>
                                    </div>
                                </div>
                            </td>
                            <td class="text-center eliminar-cell" data-prod="{{ $linea->prod_item }}" data-orden="{{ $linea->orden }}"></td>
                        </tr>
                    @empty
                        <tr><td colspan="14" class="text-muted text-center py-3">Sin l&iacute;neas. Use &laquo;Importar desde Compra &Aacute;gil&raquo; o &laquo;Agregar producto&raquo;.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        @if($lineas->isNotEmpty())
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
                <a href="{{ route('admin.cotizaciones.export.archivo', $nota->nronota) }}" class="btn btn-outline-secondary btn-sm">Descargar Archivo</a>
                <a href="{{ route('admin.cotizaciones.export.excel', $nota->nronota) }}" class="btn btn-outline-secondary btn-sm">Descargar Excel</a>
                <a href="{{ route('admin.cotizaciones.export.guia', $nota->nronota) }}" class="btn btn-outline-secondary btn-sm">Descargar Gu&iacute;a</a>
                <a href="{{ route('admin.cotizaciones.export.guia-ingreso', $nota->nronota) }}" class="btn btn-outline-secondary btn-sm">Descargar Gu&iacute;a Ingreso</a>
            @endif
        </fieldset>
        </div>
    </form>

    @foreach($lineas as $idx => $row)
        @php $linea = $row['linea']; @endphp
        <form method="post" action="{{ route('admin.cotizaciones.lineas.destroy', $nota->nronota) }}" class="d-none form-eliminar-linea" data-prod="{{ $linea->prod_item }}" data-orden="{{ $linea->orden }}">
            @csrf
            @method('DELETE')
            <input type="hidden" name="prod_item" value="{{ $linea->prod_item }}">
            <input type="hidden" name="orden" value="{{ $linea->orden }}">
        </form>
    @endforeach

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
                                    <th style="width:56px"></th>
                                    <th style="width:90px">C&oacute;digo</th>
                                    <th>Descripci&oacute;n</th>
                                    <th style="width:80px">Familia</th>
                                    <th class="text-end" style="width:90px">Precio</th>
                                </tr>
                            </thead>
                            <tbody id="modal-buscar-resultados">
                                <tr><td colspan="5" class="text-muted text-center py-3">Sin resultados.</td></tr>
                            </tbody>
                        </table>
                    </div>
                </div>
                <div class="modal-footer py-2">
                    <span class="small text-muted me-auto">Clic en fila para agregar al detalle.</span>
                    <button type="button" class="btn btn-secondary btn-sm" data-bs-dismiss="modal">Cerrar</button>
                </div>
            </div>
        </div>
    </div>

    <div class="modal fade" id="modal-importar-compra-agil" tabindex="-1" aria-labelledby="modal-importar-compra-agil-label" aria-hidden="true">
        <div class="modal-dialog modal-xl modal-dialog-scrollable">
            <div class="modal-content">
                <div class="modal-header py-2">
                    <h2 class="modal-title fs-6" id="modal-importar-compra-agil-label">Importar desde Compra &Aacute;gil</h2>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Cerrar"></button>
                </div>
                <div class="modal-body py-2">
                    <p class="small text-muted mb-2">
                        Copie y pegue el texto de la p&aacute;gina de Compra &Aacute;gil (Mercado P&uacute;blico): cabecera con n&uacute;mero de cotizaci&oacute;n, cliente, RUT y listado de productos con ID.
                    </p>
                    <p class="small mb-2" id="importar-compra-agil-detalle-actual">
                        <strong>Cotizaci&oacute;n actual:</strong>
                        <span id="importar-compra-agil-detalle-actual-texto">
                            {{ $resumenLineas['total'] }} l&iacute;nea(s)
                            ({{ $resumenLineas['con_agile'] }} con ID Agile, {{ $resumenLineas['sin_agile'] }} sin ID Agile).
                        </span>
                    </p>
                    <textarea
                        id="importar-compra-agil-texto"
                        class="form-control form-control-sm font-monospace mb-2"
                        rows="8"
                        placeholder="Detalle de la cotización 1161-172-COT26&#10;Nombre&#10;...&#10;SERVICIO AGRICOLA Y GANADERO&#10;RUT 61.303.000-7&#10;...&#10;Limpiadores de uso general ID: 31237835&#10;LIMPIADOR DE PISOS..."
                    ></textarea>
                    <div id="importar-compra-agil-alerta" class="alert alert-danger py-2 px-3 small mb-2 d-none" role="alert">
                        <i class="bi bi-exclamation-octagon-fill me-1"></i>
                        <span id="importar-compra-agil-alerta-texto"></span>
                    </div>
                    <div class="d-flex flex-wrap align-items-center gap-2 mb-2">
                        <button type="button" class="btn btn-primary btn-sm" id="btn-importar-compra-agil-analizar">
                            <i class="bi bi-search"></i> Analizar
                        </button>
                        <span id="importar-compra-agil-estado" class="small text-muted"></span>
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
                    <div id="importar-compra-agil-progreso-wrap" class="d-none w-100">
                        <div class="progress" style="height:14px">
                            <div id="importar-compra-agil-progreso" class="progress-bar progress-bar-striped progress-bar-animated bg-primary" role="progressbar" style="width:0%" aria-valuenow="0" aria-valuemin="0" aria-valuemax="100">0%</div>
                        </div>
                        <p id="importar-compra-agil-progreso-texto" class="small text-primary fw-semibold mb-0 mt-1">Preparando importaci&oacute;n...</p>
                    </div>
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
</div>
@endsection

@push('scripts')
<script src="https://cdn.jsdelivr.net/npm/sortablejs@1.15.6/Sortable.min.js"></script>
<script src="{{ asset('js/product-image.js') }}" defer></script>
<script>
(function () {
    const requiereNumeroCotizacion = @json($requiereNumeroCotizacion);

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

    function asegurarNumeroCotizacion() {
        if (!requiereNumeroCotizacion) {
            return true;
        }
        const enc = document.getElementById('encargado');
        const valor = String(enc?.value || '').trim();
        if (!valor) {
            dlgAlert('Debe ingresar el número de cotización y guardarlo antes de continuar.', { title: 'Número de cotización' });
            enc?.focus();
            return false;
        }
        dlgAlert('Guarde el número de cotización con el botón «Guardar número» antes de continuar.', { title: 'Número de cotización' });
        enc?.focus();
        return false;
    }

    const fmt = n => '$' + Math.round(n).toLocaleString('es-CL');
    const montototal = document.getElementById('montototal');
    const factorInput = document.getElementById('factor_precio_venta');

    function parseFactorChile(texto) {
        const t = String(texto || '').trim().replace(/\s/g, '');
        if (!t) return null;
        const norm = t.indexOf(',') >= 0 ? t.replace(/\./g, '').replace(',', '.') : t;
        if (!/^\d+(?:\.\d{1,2})?$/.test(norm)) return null;
        const f = parseFloat(norm);
        return Number.isFinite(f) && f > 0 ? f : null;
    }

    function formatFactorChile(f) {
        return f.toFixed(2).replace('.', ',');
    }

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

    document.getElementById('form-cotizacion')?.addEventListener('submit', function (e) {
        const submitter = e.submitter;
        if (!submitter || submitter.name !== 'accion') return;
        if (submitter.value !== 'aplicar_factor' && submitter.value !== 'grabar') return;
        if (!factorInput || String(factorInput.value || '').trim() === '') return;
        const parsed = parseFactorChile(factorInput.value);
        if (parsed === null) {
            e.preventDefault();
            factorInput.classList.add('is-invalid');
            factorInput.focus();
            dlgAlert('El factor debe ser un número positivo con hasta 2 decimales (ej.: 1,30).', { title: 'Factor inválido' });
            return;
        }
        factorInput.value = formatFactorChile(parsed);
    });

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
        const elimTd = tr.querySelector('.eliminar-cell');
        if (!elimTd) return;
        const prod = elimTd.dataset.prod;
        const orden = elimTd.dataset.orden;
        const delForm = document.querySelector('.form-eliminar-linea[data-prod="' + prod + '"][data-orden="' + orden + '"]');
        if (delForm) {
            const btn = document.createElement('button');
            btn.type = 'button';
            btn.className = 'btn btn-outline-danger btn-sm py-0 px-2';
            btn.textContent = 'Eliminar';
            btn.addEventListener('click', () => {
                dlgConfirm('¿Eliminar línea?', { title: 'Eliminar línea', type: 'danger' }).then(ok => {
                    if (ok) delForm.submit();
                });
            });
            elimTd.appendChild(btn);
        }
    });

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

    function sincronizarOrdenGrilla() {
        const tbody = document.querySelector('#tabla_detalle tbody');
        if (!tbody) return;

        const rows = Array.from(tbody.querySelectorAll('tr[data-linea]'));
        const total = rows.length;

        rows.forEach((row, idx) => {
            const ordenNuevo = idx + 1;
            const prod = row.dataset.prod;
            const ordenAnterior = parseInt(row.dataset.orden, 10);

            const delForm = document.querySelector(
                '.form-eliminar-linea[data-prod="' + prod + '"][data-orden="' + ordenAnterior + '"]'
            );
            if (delForm) {
                delForm.dataset.orden = String(ordenNuevo);
                const ordenInput = delForm.querySelector('input[name="orden"]');
                if (ordenInput) ordenInput.value = String(ordenNuevo);
            }

            row.dataset.orden = String(ordenNuevo);

            const elimTd = row.querySelector('.eliminar-cell');
            if (elimTd) elimTd.dataset.orden = String(ordenNuevo);

            const ordenNum = row.querySelector('.linea-orden-num');
            if (ordenNum) ordenNum.textContent = String(ordenNuevo);

            row.querySelectorAll('.linea-orden-subir, .linea-orden-bajar').forEach(btn => {
                btn.dataset.orden = String(ordenNuevo);
            });

            const btnSubir = row.querySelector('.linea-orden-subir');
            const btnBajar = row.querySelector('.linea-orden-bajar');
            if (btnSubir) btnSubir.disabled = (idx === 0);
            if (btnBajar) btnBajar.disabled = (idx === total - 1);
        });
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
                return true;
            }

            ocultarLoaderCotiz();
            dlgAlert(json.error || json.message || 'No se pudo cambiar el orden.', { title: 'Error', type: 'danger' });
            return false;
        } catch (err) {
            ocultarLoaderCotiz();
            dlgAlert('Error de conexión al cambiar el orden.', { title: 'Error', type: 'danger' });
            return false;
        } finally {
            ordenEnProceso = false;
        }
    }

    async function moverLineaOrden(btn, direccion) {
        if (!btn || btn.disabled || ordenEnProceso) return;

        const row = btn.closest('tr[data-linea]');
        btn.closest('.linea-orden-buttons')?.querySelectorAll('button').forEach(b => { b.disabled = true; });

        const ok = await cambiarOrdenLinea(btn.dataset.prod, parseInt(btn.dataset.orden, 10), {
            direccion: direccion,
        });

        if (ok && row) {
            if (direccion === 'up' && row.previousElementSibling) {
                row.parentNode.insertBefore(row, row.previousElementSibling);
            } else if (direccion === 'down' && row.nextElementSibling) {
                row.parentNode.insertBefore(row.nextElementSibling, row);
            }
            sincronizarOrdenGrilla();
        } else if (!ok) {
            btn.closest('.linea-orden-buttons')?.querySelectorAll('button').forEach(b => { b.disabled = false; });
        }
    }

    document.querySelectorAll('.linea-orden-subir').forEach(btn => {
        btn.addEventListener('click', () => moverLineaOrden(btn, 'up'));
    });

    document.querySelectorAll('.linea-orden-bajar').forEach(btn => {
        btn.addEventListener('click', () => moverLineaOrden(btn, 'down'));
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
            onEnd: async function (evt) {
                if (evt.oldIndex === evt.newIndex || evt.oldIndex == null || evt.newIndex == null) {
                    return;
                }

                const row = evt.item;
                const prodItem = row.dataset.prod;
                const orden = parseInt(row.dataset.orden, 10);
                const ordenNuevo = evt.newIndex + 1;

                const ok = await cambiarOrdenLinea(prodItem, orden, { orden_nuevo: ordenNuevo });
                if (ok) {
                    sincronizarOrdenGrilla();
                } else {
                    revertirSortable(evt);
                }
            },
        });
    }

    const lineasUrl = @json(route('admin.cotizaciones.lineas.store', $nota->nronota));
    const csrf = document.querySelector('meta[name="csrf-token"]')?.content;
    let agregandoLinea = false;

    async function agregarProducto(p) {
        if (!p?.prod_item || agregandoLinea) {
            return false;
        }

        const cantidad = document.getElementById('modal-cantidad')?.value || '1';
        const prodValor = p.prod_valor ?? 0;
        const prodValorCosto = p.prod_valor_costo ?? '';

        agregandoLinea = true;

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

            if (res.ok) {
                mostrarLoaderCotiz();
                window.location.reload();
                return true;
            }

            const json = await res.json().catch(() => ({}));
            ocultarLoaderCotiz();
            try {
                sessionStorage.removeItem('page-loader-pending');
            } catch (e) {}
            dlgAlert(json.error || json.message || 'No se pudo agregar la línea.', { title: 'Error', type: 'danger' });
            return false;
        } catch (err) {
            ocultarLoaderCotiz();
            try {
                sessionStorage.removeItem('page-loader-pending');
            } catch (e) {}
            dlgAlert('Error de conexión al agregar producto.', { title: 'Error', type: 'danger' });
            return false;
        } finally {
            agregandoLinea = false;
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
    const bsModal = modalEl ? new bootstrap.Modal(modalEl) : null;
    let buscarAbort = null;
    let resultadosActuales = [];
    let filaActiva = -1;

    function fmtPrecio(n) {
        return '$' + Math.round(Number(n) || 0).toLocaleString('es-CL');
    }

    function buscarProductoThumbHtml(p) {
        const src = p?.image_url ? escHtml(p.image_url) : buscarConfig.placeholderImg;

        return '<img src="' + src + '" alt="" class="cotiz-buscar-thumb" loading="lazy" '
            + 'onerror="this.onerror=null;this.src=\'' + buscarConfig.placeholderImg + '\'">';
    }

    async function seleccionarProducto(p) {
        if (agregandoLinea) {
            return;
        }

        mostrarLoaderCotiz();
        await new Promise(resolve => requestAnimationFrame(() => requestAnimationFrame(resolve)));

        if (modalEstado) {
            modalEstado.textContent = 'Agregando producto...';
        }
        bsModal?.hide();
        agregarProducto(p);
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
            modalBody.innerHTML = '<tr><td colspan="5" class="text-muted text-center py-3">Sin resultados.</td></tr>';
            if (modalEstado) {
                modalEstado.textContent = meta?.q
                    ? 'No se encontraron productos similares para «' + meta.q + '».'
                    : 'Escriba el texto del cliente o descripción y pulse Buscar.';
            }
            return;
        }

        modalBody.innerHTML = '';
        resultadosActuales.forEach((p, idx) => {
            const tr = document.createElement('tr');
            tr.dataset.idx = String(idx);
            tr.className = 'cotiz-buscar-fila';
            tr.tabIndex = 0;
            tr.innerHTML =
                '<td class="text-center p-1">' + buscarProductoThumbHtml(p) + '</td>' +
                '<td class="align-middle"><code class="small">' + (p.prod_item || '') + '</code></td>' +
                '<td class="align-middle small">' + (p.prod_nombre || '') + '</td>' +
                '<td class="align-middle small text-muted">' + (p.prod_familia || '') + '</td>' +
                '<td class="align-middle text-end fw-semibold">' + fmtPrecio(p.prod_valor) + '</td>';

            tr.addEventListener('click', () => seleccionarProducto(p));
            tr.addEventListener('keydown', e => {
                if (e.key === 'Enter') {
                    e.preventDefault();
                    seleccionarProducto(p);
                }
            });
            modalBody.appendChild(tr);
        });

        if (modalEstado && meta) {
            modalEstado.textContent = meta.count + ' producto(s) — ordenados por similitud y precio (más barato primero).';
        }

        marcarFilaActiva(0);
    }

    async function ejecutarBusqueda(q) {
        if (buscarAbort) buscarAbort.abort();
        buscarAbort = new AbortController();

        if (q.length < buscarConfig.minChars) {
            renderResultados([], { q });
            if (modalEstado) {
                modalEstado.textContent = 'Escriba al menos ' + buscarConfig.minChars + ' caracteres para buscar.';
            }
            return;
        }

        if (modalEstado) modalEstado.textContent = 'Buscando...';

        try {
            const params = new URLSearchParams({
                q,
                limit: String(buscarConfig.limit),
            });
            const res = await fetch(buscarConfig.url + '?' + params.toString(), {
                headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
                signal: buscarAbort.signal,
            });
            const json = await res.json();
            renderResultados(json.data || [], json.meta || { q, count: (json.data || []).length });
        } catch (err) {
            if (err.name === 'AbortError') return;
            if (modalEstado) modalEstado.textContent = 'Error al buscar. Intente de nuevo.';
        }
    }

    function abrirModalBuscar() {
        if (!asegurarNumeroCotizacion()) return;
        if (!bsModal || !modalInput) return;
        modalInput.value = '';
        renderResultados([], {});
        if (modalEstado) {
            modalEstado.textContent = 'Escriba el texto del cliente o descripción y pulse Buscar.';
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
            modalEstado.textContent = 'Escriba el texto del cliente o descripción y pulse Buscar.';
        }
    }

    btnAbrirBuscar?.addEventListener('click', () => abrirModalBuscar());
    btnModalBuscar?.addEventListener('click', () => lanzarBusquedaModal());
    document.getElementById('btn-modal-buscar-limpiar')?.addEventListener('click', limpiarBusquedaModal);

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
    };
    const modalImportarEl = document.getElementById('modal-importar-compra-agil');
    const btnAbrirImportar = document.getElementById('btn-abrir-importar-compra-agil');
    const importarTexto = document.getElementById('importar-compra-agil-texto');
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
    const btnImportarAnalizar = document.getElementById('btn-importar-compra-agil-analizar');
    const btnImportarConfirmar = document.getElementById('btn-importar-compra-agil-confirmar');
    const bsModalImportar = modalImportarEl ? new bootstrap.Modal(modalImportarEl) : null;
    let importPreviewData = null;
    let importandoCompraAgil = false;

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
        const pct = totalLineas > 0 ? Math.round((procesadas / totalLineas) * 100) : (actualNum > 0 ? 100 : 0);

        if (importarProgresoBar) {
            importarProgresoBar.style.width = pct + '%';
            importarProgresoBar.setAttribute('aria-valuenow', String(pct));
            importarProgresoBar.textContent = pct + '%';
        }

        if (importarProgresoTexto) {
            if (textoExtra) {
                importarProgresoTexto.textContent = textoExtra;
            } else if (totalLineas > 0) {
                importarProgresoTexto.textContent = procesadas + ' de ' + totalLineas + ' líneas (' + pct + '%)';
            } else {
                importarProgresoTexto.textContent = 'Actualizando cabecera...';
            }
        }
    }

    function resetImportCompraAgilModal() {
        importPreviewData = null;
        importandoCompraAgil = false;
        if (importarTexto) importarTexto.value = '';
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
        const texto = String(importarTexto?.value || '').trim();
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

    function mostrarProgresoImportar() {
        if (importarProgresoWrap) {
            importarProgresoWrap.classList.remove('d-none');
            importarProgresoWrap.scrollIntoView({ block: 'nearest', behavior: 'smooth' });
        }
        actualizarProgresoImportar(0, importPreviewData?.resumen?.total || 0, 'Preparando importación...');
        if (importarEstado) importarEstado.textContent = '';
        limpiarImportAlerta();
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

        const texto = String(importarTexto?.value || '').trim();
        if (!texto) return;

        const sinMatch = importPreviewData?.resumen?.pendientes || 0;
        if (sinMatch > 0) {
            const ok = await dlgConfirm(
                'Hay ' + sinMatch + ' línea(s) pendientes de vincular. Se importarán todas; use Buscar en cada fila para asignar el producto del maestro. ¿Continuar?',
                { title: 'Importar con pendientes', type: 'warning' },
            );
            if (!ok) return;
        }

        importandoCompraAgil = true;
        if (btnImportarConfirmar) btnImportarConfirmar.disabled = true;
        if (btnImportarAnalizar) btnImportarAnalizar.disabled = true;
        mostrarProgresoImportar();

        const total = importPreviewData?.resumen?.total || 0;

        try {
            if (total === 0) {
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
        }
    }

    btnAbrirImportar?.addEventListener('click', () => {
        resetImportCompraAgilModal();
        actualizarResumenLineas(resumenLineasInicial);
        bsModalImportar?.show();
        setTimeout(() => importarTexto?.focus(), 200);
    });

    btnImportarAnalizar?.addEventListener('click', () => analizarImportCompraAgil());
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

    function formatMoneyCotiz(n) {
        return Math.round(Number(n) || 0).toLocaleString('es-CL');
    }

    function abrirPopupVincularAgile(btn) {
        vincularFilaActual = btn.dataset.fila;
        vincularOrdenActual = btn.dataset.orden;
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
    }

    function buscarProductosVincularPopup() {
        const q = popupVincularBusqueda?.value?.trim() || '';
        const cont = popupVincularResultados;
        if (!cont) return;
        if (q.length < buscarConfig.minChars) {
            cont.innerHTML = '<p class="text-muted small">Escriba al menos ' + buscarConfig.minChars + ' caracteres.</p>';
            return;
        }

        cont.innerHTML = '<p class="text-muted small">Buscando...</p>';
        fetch(buscarConfig.url + '?q=' + encodeURIComponent(q) + '&limit=' + buscarConfig.limit, {
            headers: { Accept: 'application/json' },
        })
            .then(r => r.json())
            .then(data => {
                const items = data.data || [];
                if (!items.length) {
                    cont.innerHTML = '<p class="text-muted small">Sin resultados.</p>';
                    return;
                }
                let html = '<table class="table table-sm table-hover mb-0 cotiz-buscar-tabla"><thead><tr>'
                    + '<th style="width:56px"></th>'
                    + '<th>Código</th><th>Nombre</th><th>Costo</th><th>Venta</th><th></th>'
                    + '</tr></thead><tbody>';
                items.forEach(p => {
                    const nombre = String(p.prod_nombre || '').replace(/"/g, '&quot;');
                    html += '<tr>'
                        + '<td class="text-center p-1">' + buscarProductoThumbHtml(p) + '</td>'
                        + '<td>' + escHtml(p.prod_item) + '</td>'
                        + '<td>' + escHtml(p.prod_nombre) + '</td>'
                        + '<td>' + formatMoneyCotiz(p.prod_valor_costo) + '</td>'
                        + '<td>' + formatMoneyCotiz(p.prod_valor) + '</td>'
                        + '<td><button type="button" class="btn btn-sm btn-primary btn-seleccionar-vinculo" data-codigo="' + escHtml(p.prod_item) + '" data-costo="' + (p.prod_valor_costo || 0) + '" data-venta="' + (p.prod_valor || 0) + '" data-nombre="' + nombre + '">Seleccionar</button></td>'
                        + '</tr>';
                });
                html += '</tbody></table>';
                cont.innerHTML = html;
                cont.querySelectorAll('.btn-seleccionar-vinculo').forEach(b => {
                    b.addEventListener('click', () => seleccionarVinculoAgile(
                        b.dataset.codigo,
                        parseInt(b.dataset.costo, 10) || 0,
                        parseInt(b.dataset.venta, 10) || 0,
                        b.dataset.nombre,
                    ));
                });
            })
            .catch(() => {
                cont.innerHTML = '<p class="text-danger small">Error al buscar.</p>';
            });
    }

    function encontrarFilaAgile(orden, agileId) {
        return Array.from(document.querySelectorAll('#tabla_detalle tbody tr[data-prod-item-agile]'))
            .find(tr => String(tr.dataset.orden) === String(orden)
                && String(tr.dataset.prodItemAgile || '') === String(agileId)) || null;
    }

    function actualizarFilaVinculada(orden, agileId, linea) {
        const tr = encontrarFilaAgile(orden, agileId);
        if (!tr || !linea) return;

        const codigo = String(linea.prod_item || '').trim();
        const prodAnterior = tr.dataset.prod || '';

        const delForm = document.querySelector('.form-eliminar-linea[data-orden="' + orden + '"][data-prod="' + prodAnterior + '"]');
        if (delForm) {
            delForm.dataset.prod = codigo;
            const delProdInput = delForm.querySelector('input[name="prod_item"]');
            if (delProdInput) delProdInput.value = codigo;
        }

        tr.dataset.prod = codigo;
        tr.classList.remove('linea-pendiente-vinculo');

        const codigoSpan = tr.querySelector('.linea-codigo-interno');
        if (codigoSpan) {
            codigoSpan.textContent = codigo;
            codigoSpan.classList.remove('text-warning', 'fw-semibold');
        }

        const hiddenProd = tr.querySelector('input[name*="[prod_item]"]');
        if (hiddenProd) hiddenProd.value = codigo;

        const nombreCell = tr.querySelector('.linea-prod-nombre');
        if (nombreCell) {
            nombreCell.textContent = linea.prod_nombre || codigo;
            nombreCell.classList.remove('text-warning-emphasis');
        }

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
            if (el.classList.contains('eliminar-cell') || el.classList.contains('linea-orden-subir') || el.classList.contains('linea-orden-bajar')) {
                el.dataset.prod = codigo;
            }
        });

        recalcularMontoTotal();
    }

    async function seleccionarVinculoAgile(codigo, costo, venta, nombre) {
        if (vincularOrdenActual == null || !vincularAgileIdActual) return;

        const orden = parseInt(vincularOrdenActual, 10);
        const agileId = vincularAgileIdActual;

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
                    orden,
                    prod_item_agile: agileId,
                    prod_item: codigo,
                    prod_valor: venta,
                }),
            });
            const json = await res.json().catch(() => ({}));
            if (!res.ok) {
                dlgAlert(json.error || 'No se pudo vincular el producto.', { title: 'Error', type: 'danger' });
                return;
            }
            cerrarPopupVincularAgile();
            actualizarFilaVinculada(orden, agileId, json.linea || {
                prod_item: codigo,
                prod_nombre: nombre,
                prod_valor: venta,
                prod_valor_costo: costo,
                subtotal: venta * (parseInt(document.querySelector(`#tabla_detalle tbody tr[data-orden="${orden}"] .linea-cantidad`)?.value || '1', 10) || 1),
            });
        } catch (err) {
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
})();
</script>
@endpush
