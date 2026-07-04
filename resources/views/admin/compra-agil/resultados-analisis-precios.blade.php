@extends('layouts.admin')

@section('title', 'Análisis de Precios — Resultados Compra Ágil')

@section('content')
<div class="container-fluid py-4">
    <div class="d-flex align-items-center gap-2 mb-4">
        <a href="{{ route('admin.compra-agil.resultados.index') }}" class="btn btn-outline-secondary btn-sm" data-no-loader>
            <i class="bi bi-arrow-left"></i> Volver
        </a>
        <h1 class="h3 mb-0">Análisis de Precios</h1>
    </div>

    <form method="GET" action="{{ route('admin.compra-agil.resultados.analisis-precios') }}" class="card shadow-sm mb-3" data-no-loader>
        <div class="card-body py-2">
            <div class="row g-2 align-items-end">
                <div class="col-auto">
                    <label for="f-producto" class="form-label small mb-0">Producto</label>
                    <input type="text" class="form-control form-control-sm" id="f-producto" name="producto"
                        value="{{ $filtros['producto'] ?? '' }}" placeholder="Nombre o código..." style="width:14rem" autofocus>
                </div>
                <div class="col-auto">
                    <label for="f-nronota" class="form-label small mb-0">Nota</label>
                    <input type="number" class="form-control form-control-sm" id="f-nronota" name="nronota"
                        value="{{ $filtros['nronota'] ?? '' }}" placeholder="Ej: 1234" style="width:6rem">
                </div>
                <div class="col-auto">
                    <label for="f-codigo" class="form-label small mb-0">Código CA</label>
                    <input type="text" class="form-control form-control-sm" id="f-codigo" name="codigo_proceso"
                        value="{{ $filtros['codigo_proceso'] ?? '' }}" placeholder="Ej: 2923-..." style="width:9rem">
                </div>
                <div class="col-auto">
                    <label for="f-fecha-desde" class="form-label small mb-0">Publicación desde</label>
                    <input type="date" class="form-control form-control-sm" id="f-fecha-desde" name="fecha_desde"
                        value="{{ $filtros['fecha_desde'] ?? '' }}">
                </div>
                <div class="col-auto">
                    <label for="f-fecha-hasta" class="form-label small mb-0">Publicación hasta</label>
                    <input type="date" class="form-control form-control-sm" id="f-fecha-hasta" name="fecha_hasta"
                        value="{{ $filtros['fecha_hasta'] ?? '' }}">
                </div>
                <div class="col-auto">
                    <label for="f-precio-desde" class="form-label small mb-0">Precio desde</label>
                    <input type="number" class="form-control form-control-sm" id="f-precio-desde" name="precio_desde"
                        value="{{ $filtros['precio_desde'] ?? '' }}" placeholder="$" style="width:6rem">
                </div>
                <div class="col-auto">
                    <label for="f-precio-hasta" class="form-label small mb-0">Precio hasta</label>
                    <input type="number" class="form-control form-control-sm" id="f-precio-hasta" name="precio_hasta"
                        value="{{ $filtros['precio_hasta'] ?? '' }}" placeholder="$" style="width:6rem">
                </div>
                <div class="col-auto">
                    <button type="submit" class="btn btn-primary btn-sm">
                        <i class="bi bi-search"></i> Buscar
                    </button>
                    @if(collect($filtros)->filter()->isNotEmpty())
                        <a href="{{ route('admin.compra-agil.resultados.analisis-precios') }}" class="btn btn-outline-secondary btn-sm ms-1" data-no-loader>
                            <i class="bi bi-x-lg"></i> Limpiar
                        </a>
                    @endif
                </div>
            </div>
        </div>
    </form>

    @if($lineas === null)
        <div class="alert alert-info small">
            <i class="bi bi-info-circle"></i> Ingrese un término de búsqueda para consultar precios de productos en las ofertas de Mercado Público.
        </div>
    @else
        <div class="card shadow-sm">
            <div class="card-header py-2 d-flex justify-content-between align-items-center">
                <h2 class="h6 mb-0">Resultados</h2>
                <div class="d-flex align-items-center gap-2">
                    <span class="badge text-bg-secondary">{{ $lineas->total() }} líneas</span>
                    @if($lineas->total() > 0)
                        <a href="{{ route('admin.compra-agil.resultados.analisis-precios.exportar', request()->query()) }}" class="btn btn-outline-success btn-sm" download>
                            <i class="bi bi-file-earmark-spreadsheet"></i> CSV
                        </a>
                    @endif
                </div>
            </div>
            <div class="table-responsive">
                <table class="table table-sm table-hover align-middle mb-0" style="font-size:.82rem">
                    <thead class="table-light">
                        <tr>
                            <th>Código</th>
                            <th>Producto</th>
                            <th>Descripción</th>
                            <th class="text-end">P. Unit.</th>
                            <th class="text-end">Cant.</th>
                            <th class="text-end">Total</th>
                            <th>Nota</th>
                            <th>Código CA</th>
                            <th>Publicación</th>
                            <th>Proveedor</th>
                            <th>RUT</th>
                            <th class="text-end table-warning">P.Unit. Romulo</th>
                            <th class="text-end table-warning">Cant. Romulo</th>
                            <th class="text-end table-warning">Total Romulo</th>
                            <th></th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse($lineas as $l)
                            <tr class="{{ $l->proveedor_seleccionado ? 'table-success' : '' }}{{ $l->es_propio ? ' fw-semibold' : '' }}">
                                <td class="font-monospace">{{ $l->codigo_producto ?: '—' }}</td>
                                <td>{{ $l->nombre_producto ?: '—' }}</td>
                                <td class="text-muted">{{ Str::limit($l->descripcion, 50) ?: '—' }}</td>
                                <td class="text-end">${{ number_format($l->precio_unitario ?? 0, 0, ',', '.') }}</td>
                                <td class="text-end">{{ $l->cantidad ?? '—' }}</td>
                                <td class="text-end">${{ number_format($l->monto_total ?? 0, 0, ',', '.') }}</td>
                                <td>{{ $l->nronota }}</td>
                                <td class="font-monospace">{{ $l->codigo_proceso }}</td>
                                <td class="text-muted">{{ $l->fecha_publicacion ? \Carbon\Carbon::parse($l->fecha_publicacion)->format('d/m/Y') : '—' }}</td>
                                <td>{{ $l->razon_social ?: '—' }}</td>
                                <td class="text-muted">{{ $l->rut_proveedor ?: '—' }}</td>
                                <td class="text-end table-warning">
                                    @if($l->precio_propio !== null)
                                        ${{ number_format($l->precio_propio, 0, ',', '.') }}
                                    @else
                                        —
                                    @endif
                                </td>
                                <td class="text-end table-warning">{{ $l->cantidad_propia ?? '—' }}</td>
                                <td class="text-end table-warning">
                                    @if($l->total_propio !== null)
                                        ${{ number_format($l->total_propio, 0, ',', '.') }}
                                    @else
                                        —
                                    @endif
                                </td>
                                <td>
                                    @if($l->proveedor_seleccionado)
                                        <span class="badge text-bg-success">Ganador</span>
                                    @endif
                                    @if($l->es_propio)
                                        <span class="badge text-bg-info">Propio</span>
                                    @endif
                                </td>
                            </tr>
                        @empty
                            <tr><td colspan="15" class="text-center text-muted py-4">Sin resultados para la búsqueda.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
            @if($lineas->hasPages())
                <div class="card-footer py-2 d-flex justify-content-center">
                    {{ $lineas->links() }}
                </div>
            @endif
        </div>
    @endif
</div>
@endsection
