@extends('layouts.admin')

@section('title', 'Errores de importación')

@section('content')
<div class="container-fluid py-4">
    <div class="d-flex flex-wrap justify-content-between align-items-center gap-3 mb-4">
        <div>
            <h1 class="h3 fw-bold mb-1">Errores de importación</h1>
            <p class="text-muted mb-0">
                Archivo <strong>{{ $run->archivo }}</strong>
                @if($run->finished_at)
                    — {{ $run->finished_at->timezone(config('app.timezone'))->format('d/m/Y H:i') }}
                @endif
            </p>
        </div>
        <div class="d-flex flex-wrap gap-2">
            <a href="{{ route('admin.productos.import.errores.exportar', ['run' => $run->id]) }}" class="btn btn-outline-dark" data-no-loader>
                <i class="bi bi-download"></i> Descargar CSV
            </a>
            <a href="{{ route('admin.productos.index') }}" class="btn btn-primary">
                <i class="bi bi-box-seam"></i> Ver productos
            </a>
            <a href="{{ route('admin.productos.import') }}" class="btn btn-outline-secondary">
                <i class="bi bi-upload"></i> Nueva carga
            </a>
        </div>
    </div>

    <div class="row g-3 mb-4">
        <div class="col-sm-6 col-md-3">
            <div class="card shadow-sm h-100">
                <div class="card-body py-3 text-center">
                    <div class="h4 fw-bold mb-0 text-success">{{ number_format($run->creados) }}</div>
                    <div class="small text-muted">Creados</div>
                </div>
            </div>
        </div>
        <div class="col-sm-6 col-md-3">
            <div class="card shadow-sm h-100">
                <div class="card-body py-3 text-center">
                    <div class="h4 fw-bold mb-0 text-primary">{{ number_format($run->actualizados) }}</div>
                    <div class="small text-muted">Actualizados</div>
                </div>
            </div>
        </div>
        <div class="col-sm-6 col-md-3">
            <div class="card shadow-sm h-100">
                <div class="card-body py-3 text-center">
                    <div class="h4 fw-bold mb-0">{{ number_format($run->omitidos) }}</div>
                    <div class="small text-muted">Omitidos</div>
                </div>
            </div>
        </div>
        <div class="col-sm-6 col-md-3">
            <div class="card shadow-sm border-danger h-100">
                <div class="card-body py-3 text-center">
                    <div class="h4 fw-bold mb-0 text-danger">{{ number_format($run->total_errores) }}</div>
                    <div class="small text-muted">Errores</div>
                </div>
            </div>
        </div>
    </div>

    <div class="card shadow-sm">
        <div class="card-header bg-white d-flex flex-wrap justify-content-between align-items-center gap-2">
            <span class="fw-semibold">Detalle de filas con error</span>
            <span class="small text-muted">
                Mostrando {{ $errores->firstItem() ?? 0 }}–{{ $errores->lastItem() ?? 0 }} de {{ $errores->total() }}
            </span>
        </div>
        <div class="table-responsive">
            <table class="table table-sm table-hover mb-0 align-middle">
                <thead class="table-light">
                    <tr>
                        <th style="width:64px">Fila</th>
                        <th style="width:110px">C&oacute;digo</th>
                        <th>Nombre</th>
                        <th style="width:90px">Familia</th>
                        <th>Error</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse($errores as $error)
                        <tr>
                            <td>{{ $error->fila ?? '—' }}</td>
                            <td><code>{{ $error->codigo ?? '' }}</code></td>
                            <td class="small">{{ $error->nombre ?? '' }}</td>
                            <td class="small text-muted">{{ $error->familia ?? '' }}</td>
                            <td class="small">
                                {{ $error->mensaje }}
                                @if($error->detalle)
                                    <span class="text-muted">({{ $error->detalle }})</span>
                                @endif
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="5" class="text-center text-muted py-4">Sin errores registrados.</td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        @if($errores->hasPages())
            <div class="card-body border-top py-2">{{ $errores->links() }}</div>
        @endif
    </div>
</div>
@endsection
