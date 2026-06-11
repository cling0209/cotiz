@extends('layouts.admin')

@section('title', 'Listado cotizaciones')

@section('content')
<div class="container-fluid py-4">
    <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-4">
        <h1 class="h3 mb-0">Listado cotizaciones</h1>
        <div class="d-flex gap-2">
            <form action="{{ route('admin.cotizaciones.create') }}" method="post">@csrf
                <button type="submit" class="btn btn-primary btn-sm"><i class="bi bi-plus-lg"></i> Nueva</button>
            </form>
            <a href="{{ route('admin.cotizaciones.retomar') }}" class="btn btn-outline-secondary btn-sm">Retomar &uacute;ltima</a>
        </div>
    </div>

    <div class="card shadow-sm mb-4">
        <div class="card-body">
            <form method="get" action="{{ route('admin.cotizaciones.index') }}" class="row g-3 align-items-end">
                <input type="hidden" name="orden_campo" value="{{ $filtros['orden_campo'] }}">
                <input type="hidden" name="orden_dir" value="{{ $filtros['orden_dir'] }}">
                <div class="col-md-2">
                    <label class="form-label">Desde</label>
                    <input type="date" name="fechadesde" class="form-control form-control-sm" value="{{ $filtros['fechadesde'] }}">
                </div>
                <div class="col-md-2">
                    <label class="form-label">Hasta</label>
                    <input type="date" name="fechahasta" class="form-control form-control-sm" value="{{ $filtros['fechahasta'] }}">
                </div>
                <div class="col-md-auto">
                    <button type="submit" class="btn btn-secondary btn-sm">Buscar por fecha</button>
                </div>
                <div class="col-md-2">
                    <label class="form-label">N&ordm; nota</label>
                    <input type="number" name="nronota" class="form-control form-control-sm" value="{{ $filtros['nronota'] ?: '' }}" min="0">
                </div>
                <div class="col-md-auto">
                    <button type="submit" class="btn btn-secondary btn-sm">Buscar nota</button>
                </div>
                <div class="col-md-3">
                    <label class="form-label">N&ordm; cotizaci&oacute;n (encargado)</label>
                    <input type="text" name="cotizacion" class="form-control form-control-sm" value="{{ $filtros['cotizacion'] }}">
                </div>
                <div class="col-md-auto">
                    <button type="submit" class="btn btn-secondary btn-sm">Buscar cotiz.</button>
                </div>
            </form>
        </div>
    </div>

    <div class="card shadow-sm">
        <div class="table-responsive">
            <table class="table table-hover table-sm align-middle mb-0">
                <thead class="table-dark">
                    <tr>
                        @php
                            $sortLink = fn ($campo, $dir) => route('admin.cotizaciones.index', array_merge($filtros, ['orden_campo' => $campo, 'orden_dir' => $dir, 'page' => 1]));
                        @endphp
                        <th>
                            Nota
                            <a href="{{ $sortLink('nronota', 'ASC') }}" class="text-white-50 small">&#9650;</a>
                            <a href="{{ $sortLink('nronota', 'DESC') }}" class="text-white-50 small">&#9660;</a>
                        </th>
                        <th>
                            Fecha
                            <a href="{{ $sortLink('fecha', 'ASC') }}" class="text-white-50 small">&#9650;</a>
                            <a href="{{ $sortLink('fecha', 'DESC') }}" class="text-white-50 small">&#9660;</a>
                        </th>
                        <th>Empresa</th>
                        <th class="text-end">
                            Total
                            <a href="{{ $sortLink('total', 'ASC') }}" class="text-white-50 small">&#9650;</a>
                            <a href="{{ $sortLink('total', 'DESC') }}" class="text-white-50 small">&#9660;</a>
                        </th>
                        <th>Cotizaci&oacute;n</th>
                        <th>Usuario</th>
                        <th>Estado</th>
                        <th></th>
                    </tr>
                </thead>
                <tbody>
                    @forelse($cotizaciones as $nota)
                        <tr>
                            <td>{{ $nota->nronota }}</td>
                            <td>{{ $nota->fecha?->format('d/m/Y') }}</td>
                            <td>{{ $nota->empresa }}</td>
                            <td class="text-end">${{ number_format($nota->total_calculado ?? 0, 0, ',', '.') }}</td>
                            <td>{{ $nota->encargado }}</td>
                            <td>{{ $nota->usuarioRel?->fullName() ?: $nota->usuario }}</td>
                            <td>{{ $nota->estado ?: '—' }}</td>
                            <td class="text-end">
                                <a href="{{ route('admin.cotizaciones.edit', $nota->nronota) }}" class="btn btn-outline-primary btn-sm">Ver</a>
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="8" class="text-center text-muted py-4">Sin cotizaciones.</td></tr>
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
