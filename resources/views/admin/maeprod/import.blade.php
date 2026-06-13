@extends('layouts.admin')

@section('title', 'Carga masiva de productos')

@section('content')
<div class="container-fluid py-4">
    <div class="d-flex flex-wrap justify-content-between align-items-center gap-3 mb-4">
        <div>
            <h1 class="h3 fw-bold mb-1">Carga masiva de productos</h1>
            <p class="text-muted mb-0">Descarga la plantilla CSV, compl&eacute;tala y s&uacute;bela para crear o actualizar productos en el maestro (maeprod).</p>
        </div>
        <a href="{{ route('admin.productos.index') }}" class="btn btn-outline-secondary">
            <i class="bi bi-arrow-left"></i> Volver al listado
        </a>
    </div>

    @if(session('import_errors'))
        <div class="alert alert-warning">
            <strong>Algunas filas no se importaron:</strong>
            <ul class="mb-0 mt-2">
                @foreach(session('import_errors') as $error)
                    <li>{{ $error }}</li>
                @endforeach
            </ul>
        </div>
    @endif

    <div class="row g-4">
        <div class="col-lg-5">
            <div class="card shadow-sm h-100">
                <div class="card-header bg-white fw-semibold">1. Descargar plantilla</div>
                <div class="card-body">
                    <p class="text-muted">
                        Archivo CSV con separador <strong>punto y coma (;)</strong>.
                        UTF-8 o Excel Windows (Latin-1); el sistema convierte la codificaci&oacute;n autom&aacute;ticamente.
                        Incluye una fila de ejemplo.
                    </p>
                    <dl class="small mb-4">
                        <dt class="fw-semibold">Columnas obligatorias</dt>
                        <dd><code>codigo</code>, <code>nombre</code>, <code>familia</code>, <code>precio</code></dd>
                        <dt class="fw-semibold">Columnas opcionales</dt>
                        <dd class="mb-0">
                            <code>costo</code>, <code>nombre_archivo</code> (ej. <code>90503_medium.jpg</code>),
                            <code>gramaje</code>, <code>stock</code>, <code>softland</code>
                        </dd>
                    </dl>
                    <div class="d-flex flex-wrap gap-2">
                        <a href="{{ route('admin.productos.import.template') }}" class="btn btn-outline-primary" data-no-loader>
                            <i class="bi bi-download"></i> Descargar plantilla
                        </a>
                        <a href="{{ route('admin.productos.export') }}" class="btn btn-outline-success" data-no-loader>
                            <i class="bi bi-file-earmark-spreadsheet"></i> Descargar productos actuales
                        </a>
                    </div>
                </div>
            </div>
        </div>

        <div class="col-lg-7">
            <div class="card shadow-sm h-100">
                <div class="card-header bg-white fw-semibold">2. Subir archivo</div>
                <div class="card-body">
                    <form method="post" action="{{ route('admin.productos.import.store') }}" enctype="multipart/form-data">
                        @csrf
                        <div class="mb-3">
                            <label for="archivo" class="form-label">Archivo CSV *</label>
                            <input type="file" id="archivo" name="archivo" accept=".csv,text/csv" class="form-control @error('archivo') is-invalid @enderror" required>
                            @error('archivo')
                                <div class="invalid-feedback">{{ $message }}</div>
                            @enderror
                            <div class="form-text">
                                Hasta 20 MB. Si el c&oacute;digo ya existe, el producto se actualiza; si no, se crea.
                            </div>
                        </div>

                        <button type="submit" class="btn btn-primary">
                            <i class="bi bi-upload"></i> Importar productos
                        </button>
                    </form>
                </div>
            </div>
        </div>
    </div>
</div>
@endsection
