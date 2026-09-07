@extends('layouts.admin')

@section('title', 'Eliminación masiva de productos')

@section('content')
@php
    /** @var array{deleted?: int, not_deleted?: int, total?: int, failures?: list<array{fila: int, codigo: string, motivo: string}>}|null $resultado */
    $resultado = $resultado ?? null;
@endphp
<div class="container-fluid py-4">
    <div class="d-flex flex-wrap justify-content-between align-items-center gap-3 mb-4">
        <div>
            <h1 class="h3 fw-bold mb-1">Eliminaci&oacute;n masiva de productos</h1>
            <p class="text-muted mb-0">
                Suba un Excel con c&oacute;digos del maestro (<code>prod_item</code>) para eliminarlos del cat&aacute;logo.
            </p>
        </div>
        <a href="{{ route('admin.productos.index') }}" class="btn btn-outline-secondary">
            <i class="bi bi-arrow-left"></i> Volver al listado
        </a>
    </div>

    <div class="row g-4">
        <div class="col-lg-5">
            <div class="card shadow-sm h-100">
                <div class="card-header bg-white fw-semibold">1. Plantilla</div>
                <div class="card-body">
                    <p class="text-muted small">
                        El archivo debe tener una columna <code>codigo</code> (o la primera columna con los c&oacute;digos).
                        Formatos: <strong>.xlsx</strong>, <strong>.xls</strong> o <strong>.csv</strong>.
                    </p>
                    <a href="{{ route('admin.productos.bulk-delete.template') }}" class="btn btn-outline-primary btn-sm" data-no-loader>
                        <i class="bi bi-download"></i> Descargar plantilla Excel
                    </a>
                </div>
            </div>
        </div>
        <div class="col-lg-7">
            <div class="card shadow-sm h-100 border-danger-subtle">
                <div class="card-header bg-white fw-semibold text-danger">2. Subir y eliminar</div>
                <div class="card-body">
                    <div class="alert alert-warning small mb-3">
                        Esta acci&oacute;n elimina productos del maestro. Las cotizaciones existentes conservan sus l&iacute;neas,
                        pero el c&oacute;digo ya no estar&aacute; disponible en el cat&aacute;logo.
                    </div>
                    <form method="post"
                          action="{{ route('admin.productos.bulk-delete.process') }}"
                          enctype="multipart/form-data"
                          id="form-bulk-delete"
                          data-confirm="¿Está seguro de eliminar los productos del archivo? Esta acción no se puede deshacer.">
                        @csrf
                        <div class="mb-3">
                            <label for="archivo" class="form-label small">Archivo con c&oacute;digos</label>
                            <input type="file" name="archivo" id="archivo"
                                   class="form-control form-control-sm @error('archivo') is-invalid @enderror"
                                   accept=".xlsx,.xls,.csv,application/vnd.openxmlformats-officedocument.spreadsheetml.sheet,application/vnd.ms-excel,text/csv"
                                   required>
                            @error('archivo')
                                <div class="invalid-feedback">{{ $message }}</div>
                            @enderror
                        </div>
                        <button type="submit" class="btn btn-danger btn-sm" id="btn-bulk-delete">
                            <i class="bi bi-trash"></i> Procesar eliminaci&oacute;n
                        </button>
                    </form>
                </div>
            </div>
        </div>
    </div>

    @if(is_array($resultado))
        <div class="card shadow-sm mt-4">
            <div class="card-header bg-white fw-semibold">Resultado</div>
            <div class="card-body">
                <div class="row g-3 mb-3">
                    <div class="col-sm-4">
                        <div class="border rounded p-3 h-100">
                            <div class="text-muted small">C&oacute;digos en archivo</div>
                            <div class="fs-4 fw-semibold tabular-nums">{{ number_format((int) ($resultado['total'] ?? 0), 0, ',', '.') }}</div>
                        </div>
                    </div>
                    <div class="col-sm-4">
                        <div class="border rounded p-3 h-100 border-success-subtle">
                            <div class="text-muted small">Eliminados</div>
                            <div class="fs-4 fw-semibold text-success tabular-nums">{{ number_format((int) ($resultado['deleted'] ?? 0), 0, ',', '.') }}</div>
                        </div>
                    </div>
                    <div class="col-sm-4">
                        <div class="border rounded p-3 h-100 border-warning-subtle">
                            <div class="text-muted small">No eliminados</div>
                            <div class="fs-4 fw-semibold text-warning-emphasis tabular-nums">{{ number_format((int) ($resultado['not_deleted'] ?? 0), 0, ',', '.') }}</div>
                        </div>
                    </div>
                </div>

                @if(!empty($resultado['failures']))
                    <h2 class="h6 fw-semibold mb-2">Detalle de no eliminados</h2>
                    <div class="table-responsive">
                        <table class="table table-sm table-hover align-middle mb-0">
                            <thead class="table-light">
                                <tr>
                                    <th style="width:5rem">Fila</th>
                                    <th style="width:10rem">C&oacute;digo</th>
                                    <th>Motivo</th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach($resultado['failures'] as $failure)
                                    <tr>
                                        <td class="tabular-nums">{{ $failure['fila'] }}</td>
                                        <td>
                                            @if(($failure['codigo'] ?? '') !== '')
                                                <code>{{ $failure['codigo'] }}</code>
                                            @else
                                                <span class="text-muted">—</span>
                                            @endif
                                        </td>
                                        <td>{{ $failure['motivo'] }}</td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                @endif
            </div>
        </div>
    @endif
</div>
@endsection
