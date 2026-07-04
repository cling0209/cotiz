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
                        value="{{ $filtros['producto'] ?? '' }}" placeholder="Nombre, descripción o código..." style="width:18rem" autofocus>
                </div>
                <div class="col-auto">
                    <label for="f-nronota" class="form-label small mb-0">Nota</label>
                    <input type="number" class="form-control form-control-sm" id="f-nronota" name="nronota"
                        value="{{ $filtros['nronota'] ?? '' }}" placeholder="Ej: 1234" style="width:7rem">
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
                <span class="badge text-bg-secondary">{{ $lineas->total() }} líneas</span>
            </div>
            <div class="table-responsive">
                <table class="table table-sm table-hover align-middle mb-0">
                    <thead class="table-light">
                        <tr>
                            <th>Código</th>
                            <th>Producto</th>
                            <th class="text-end">P. Unitario</th>
                            <th class="text-end">Cantidad</th>
                            <th class="text-end">Total</th>
                            <th>Nota</th>
                            <th>Código CA</th>
                            <th>Publicación</th>
                            <th>Proveedor</th>
                            <th>RUT</th>
                            <th></th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse($lineas as $l)
                            <tr class="{{ $l->proveedor_seleccionado ? 'table-success' : '' }}{{ $l->es_propio ? ' fw-semibold' : '' }}">
                                <td class="font-monospace small">{{ $l->codigo_producto ?: '—' }}</td>
                                <td class="small">{{ $l->nombre_producto ?: $l->descripcion ?: '—' }}</td>
                                <td class="text-end small">${{ number_format($l->precio_unitario ?? 0, 0, ',', '.') }}</td>
                                <td class="text-end small">{{ $l->cantidad ?? '—' }}</td>
                                <td class="text-end small">${{ number_format($l->monto_total ?? 0, 0, ',', '.') }}</td>
                                <td>{{ $l->nronota }}</td>
                                <td class="font-monospace small">{{ $l->codigo_proceso }}</td>
                                <td class="small text-muted">{{ $l->fecha_publicacion ? \Carbon\Carbon::parse($l->fecha_publicacion)->format('d/m/Y') : '—' }}</td>
                                <td class="small">{{ $l->razon_social ?: '—' }}</td>
                                <td class="small text-muted">{{ $l->rut_proveedor ?: '—' }}</td>
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
                            <tr><td colspan="11" class="text-center text-muted py-4">Sin resultados para la búsqueda.</td></tr>
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
