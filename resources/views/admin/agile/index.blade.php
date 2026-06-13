@extends('layouts.admin')

@section('title', 'Recepción Agile')

@section('content')
<div class="container-fluid py-4">
    <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-4">
        <h1 class="h3 mb-0">Recepción de cotizaciones API</h1>
        <a href="{{ route('admin.cotizaciones.index') }}" class="btn btn-outline-secondary btn-sm">Listado cotizaciones</a>
    </div>

    <div class="card shadow-sm mb-4">
        <div class="card-body">
            <form method="get" action="{{ route('admin.agile.index') }}" class="row g-3 align-items-end">
                <div class="col-md-4">
                    <label class="form-label">Código cotización</label>
                    <div class="input-group input-group-sm">
                        <input type="hidden" name="campo" value="encargado">
                        <input type="text" name="valor" class="form-control" value="{{ $filtros['campo'] === 'encargado' ? $filtros['valor'] : '' }}" placeholder="Encargado / código">
                        <button type="submit" class="btn btn-secondary">Buscar</button>
                    </div>
                </div>
                <div class="col-md-3">
                    <label class="form-label">RUT empresa</label>
                    <div class="input-group input-group-sm">
                        <input type="hidden" name="campo" value="rutempresa">
                        <input type="text" name="valor" class="form-control" value="{{ $filtros['campo'] === 'rutempresa' ? $filtros['valor'] : '' }}">
                        <button type="submit" class="btn btn-secondary">Buscar</button>
                    </div>
                </div>
                <div class="col-md-4">
                    <label class="form-label">Nombre empresa</label>
                    <div class="input-group input-group-sm">
                        <input type="hidden" name="campo" value="empresa">
                        <input type="text" name="valor" class="form-control" value="{{ $filtros['campo'] === 'empresa' ? $filtros['valor'] : '' }}">
                        <button type="submit" class="btn btn-secondary">Buscar</button>
                    </div>
                </div>
            </form>
        </div>
    </div>

    <div class="card shadow-sm">
        <div class="table-responsive">
            <table class="table table-hover table-sm align-middle mb-0">
                <thead class="table-dark">
                    <tr>
                        <th>Nro. nota</th>
                        <th>Código cotización</th>
                        <th>Empresa</th>
                        <th>RUT empresa</th>
                        <th>Factor precio</th>
                        <th>Estado</th>
                        <th>Fecha estado</th>
                        <th></th>
                    </tr>
                </thead>
                <tbody>
                    @forelse($cotizaciones as $nota)
                        <tr>
                            <td>{{ $nota->nronota }}</td>
                            <td>{{ $nota->encargado }}</td>
                            <td>{{ $nota->empresa }}</td>
                            <td>{{ $nota->rutempresa }}</td>
                            <td>{{ number_format((float) ($nota->factor_precio_venta ?? config('cotiz.factor_precio_venta')), 2, ',', '') }}</td>
                            <td>{{ $nota->estado ?: '—' }}</td>
                            <td>{{ $nota->estadofecha?->format('d/m/Y H:i') }}</td>
                            <td class="text-end">
                                <a href="{{ route('admin.agile.show', $nota->nronota) }}" class="btn btn-outline-primary btn-sm">Ver</a>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="8" class="text-center text-muted py-4">Sin cotizaciones Agile pendientes.</td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        @if($cotizaciones->hasPages())
            <div class="card-footer">{{ $cotizaciones->links() }}</div>
        @endif
    </div>
</div>
@endsection
