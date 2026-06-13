@extends('layouts.admin')

@section('title', 'Resultado de importación')

@section('content')
<div class="container-fluid py-4">
    <div class="d-flex flex-wrap justify-content-between align-items-center gap-3 mb-4">
        <div>
            <h1 class="h3 fw-bold mb-1">Importación completada</h1>
            <p class="text-muted mb-0">
                Archivo <strong>{{ $run->archivo }}</strong>
                @if($run->finished_at)
                    — {{ $run->finished_at->timezone(config('app.timezone'))->format('d/m/Y H:i') }}
                @endif
            </p>
        </div>
        <div class="d-flex flex-wrap gap-2">
            <a href="{{ route('admin.productos.index') }}" class="btn btn-primary">
                <i class="bi bi-box-seam"></i> Ver productos
            </a>
            <a href="{{ route('admin.productos.import') }}" class="btn btn-outline-secondary">
                <i class="bi bi-upload"></i> Nueva carga
            </a>
        </div>
    </div>

    <div class="row g-4">
        <div class="col-md-4">
            <div class="card shadow-sm border-success h-100">
                <div class="card-body text-center">
                    <div class="display-6 fw-bold text-success">{{ number_format($run->creados) }}</div>
                    <div class="text-muted">Productos creados</div>
                </div>
            </div>
        </div>
        <div class="col-md-4">
            <div class="card shadow-sm border-primary h-100">
                <div class="card-body text-center">
                    <div class="display-6 fw-bold text-primary">{{ number_format($run->actualizados) }}</div>
                    <div class="text-muted">Productos actualizados</div>
                </div>
            </div>
        </div>
        <div class="col-md-4">
            <div class="card shadow-sm h-100">
                <div class="card-body text-center">
                    <div class="display-6 fw-bold">{{ number_format($run->omitidos) }}</div>
                    <div class="text-muted">Filas omitidas</div>
                </div>
            </div>
        </div>
    </div>

    @if($run->creados + $run->actualizados === 0)
        <div class="alert alert-warning mt-4 mb-0">
            No se importó ningún producto. Revise el archivo CSV e intente nuevamente.
        </div>
    @else
        <div class="alert alert-success mt-4 mb-0">
            La carga masiva finalizó correctamente sin errores de validación.
        </div>
    @endif
</div>
@endsection
