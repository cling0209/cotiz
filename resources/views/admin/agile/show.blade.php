@extends('layouts.admin')

@section('title', 'Agile #'.$nota->nronota)

@push('head')
<link href="{{ asset('css/agile-recepcion.css') }}?v=2" rel="stylesheet">
@endpush

@section('content')
<div class="container-fluid py-4 agile-recepcion" data-nronota="{{ $nota->nronota }}">
    <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
        <h1 class="h4 mb-0">Detalle cotización Agile #{{ $nota->nronota }}</h1>
        <a href="{{ route('admin.agile.index') }}" class="btn btn-outline-secondary btn-sm">&larr; Listado Agile</a>
    </div>

    <input type="hidden" id="detalle_hay_precio_no_actualizado" value="{{ $hayPrecioAntiguo ? '1' : '0' }}">
    <input type="hidden" id="detalle_umbral_precio_meses" value="{{ $umbralPrecioMeses }}">
    <input type="hidden" id="estado" value="{{ $nota->estado }}">

    <div class="card shadow-sm mb-3">
        <div class="card-body">
            <table class="table table-sm table-borderless mb-0 agile-cabecera">
                <tr>
                    <th class="text-muted">Cotización</th>
                    <td><strong>{{ $nota->encargado }}</strong></td>
                    <th class="text-muted">RUT</th>
                    <td>{{ $nota->rutempresa }}</td>
                    <th class="text-muted">Cliente</th>
                    <td>{{ $nota->empresa }}</td>
                </tr>
            </table>
        </div>
    </div>

    @if(!$estaAprobada)
        <div id="panelFactor" class="card shadow-sm mb-3">
            <div class="card-body py-2">
                <div class="d-flex flex-wrap align-items-center gap-3">
                    <span><strong>Último factor guardado:</strong> <span id="factor_precio_venta_mostrado">{{ $factorMostrado }}</span></span>
                    <label for="porcentaje" class="mb-0">Factor aumento precio venta:</label>
                    <input type="text" id="porcentaje" class="form-control form-control-sm" style="width:5rem" maxlength="5" placeholder="1,22" value="{{ $factorInput }}">
                    <button type="button" id="btnAceptarPorcentaje" class="btn btn-sm btn-secondary">Aplicar nuevo factor</button>
                    <span id="porcentajeError" class="text-danger small" style="display:none">Formato requerido (ej: 1,22)</span>
                    <span id="factorAplicadoOk" class="text-success small fw-semibold" style="display:none" role="status"></span>
                </div>
            </div>
        </div>
    @endif

    <div id="detalle_alerta_precio_wrap">
        @if($hayPrecioAntiguo)
            <div class="alert alert-warning py-2 mb-3">
                Hay productos cuya fecha de actualización de precio supera el umbral de <strong>{{ $umbralPrecioMeses }}</strong> mes(es).
            </div>
        @endif
    </div>

    <div class="card shadow-sm">
        <div class="card-header">
            <strong>Productos solicitados</strong> ({{ $lineas->count() }} ítems)
        </div>
        <div class="table-responsive">
            <table class="table table-striped table-hover table-sm align-middle mb-0" id="tabla-agile-detalle">
                <thead class="table-dark">
                    <tr>
                        <th>ID Agile</th>
                        <th>Descripción Agile</th>
                        <th>Código interno</th>
                        <th>Descripción interno</th>
                        <th>Fecha precio venta</th>
                        <th>Precio costo</th>
                        <th>Precio venta</th>
                        <th>Cantidad</th>
                        <th>Total</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach($lineas as $idx => $row)
                        @php $linea = $row['linea']; @endphp
                        <tr data-fila="{{ $idx }}" data-orden="{{ $linea->orden }}" data-prod-item-agile="{{ $linea->prod_item_agile }}">
                            <td>{{ $linea->prod_item_agile }}</td>
                            <td>{{ $linea->prod_descripcion_agile }}</td>
                            <td>
                                <div class="d-flex gap-1">
                                    <input type="text" class="form-control form-control-sm prod-item-input" id="prod_item_{{ $idx }}" value="{{ $row['prod_item'] }}" readonly style="max-width:7rem">
                                    @if(!$estaAprobada)
                                        <button type="button" class="btn btn-outline-secondary btn-sm btn-buscar-prod" data-fila="{{ $idx }}">Buscar</button>
                                    @endif
                                </div>
                            </td>
                            <td class="prod-nombre-cell">{{ $row['prod_nombre'] }}</td>
                            <td class="fecha-precio-cell {{ $row['prod_valor_fecha_antigua'] ? 'text-danger fw-bold' : '' }}" data-fecha-precio-antigua="{{ $row['prod_valor_fecha_antigua'] ? '1' : '0' }}">
                                {{ $row['prod_valor_fecha'] }}
                            </td>
                            <td class="costo-cell">
                                {{ number_format((int) $linea->prod_valor_costo, 0, ',', '.') }}
                                <input type="hidden" id="valor_costo_{{ $idx }}" value="{{ (int) $linea->prod_valor_costo }}">
                            </td>
                            <td>
                                @if($estaAprobada)
                                    {{ number_format((int) $linea->prod_valor, 0, ',', '.') }}
                                @else
                                    <input type="text" class="form-control form-control-sm venta-input" id="valor_venta_{{ $idx }}" value="{{ (int) $linea->prod_valor }}" style="max-width:5rem" inputmode="numeric">
                                @endif
                            </td>
                            <td>{{ (int) $linea->cantidad }}</td>
                            <td class="subtotal-cell">{{ number_format($row['subtotal'], 0, ',', '.') }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
        @if(!$estaAprobada)
            <div class="card-footer d-flex gap-2">
                <button type="button" id="btnAprobar" class="btn btn-primary btn-sm">Aprobar</button>
                <form method="post" action="{{ route('admin.agile.destroy', $nota->nronota) }}" class="d-inline" onsubmit="return confirm('¿Eliminar cotización {{ $nota->encargado }}?');">
                    @csrf
                    @method('DELETE')
                    <button type="submit" class="btn btn-danger btn-sm">Eliminar</button>
                </form>
            </div>
        @endif
    </div>
</div>

<div id="popupProducto" class="agile-popup-overlay" style="display:none">
    <div class="agile-popup-content">
        <div class="agile-popup-header">
            <h5 class="mb-0">Buscar producto</h5>
            <button type="button" class="btn-close" id="cerrarPopupProducto"></button>
        </div>
        <div class="agile-popup-body">
            <p class="small text-muted mb-2"><strong>Descripción Agile:</strong> <span id="popupTextoOriginal"></span></p>
            <div class="input-group input-group-sm mb-3">
                <input type="text" id="textoBusqueda" class="form-control" placeholder="Código o nombre">
                <button type="button" class="btn btn-secondary" id="btnBuscarProductoPopup">Buscar</button>
                <button type="button" class="btn btn-outline-secondary" id="btnLimpiarBusquedaPopup">Limpiar</button>
            </div>
            <div id="resultadosProductos" class="agile-popup-resultados"></div>
        </div>
    </div>
</div>
@endsection

@push('scripts')
<script src="{{ asset('js/agile-recepcion.js') }}?v=4"></script>
@endpush
