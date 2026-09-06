@extends('layouts.admin')

@section('title', 'Parámetros')

@section('content')
<div class="container-fluid py-4">
    <div class="mb-4">
        <h1 class="h3 fw-bold mb-1">Parámetros</h1>
        <p class="text-muted mb-0">Valores de configuración usados por reportes y procesos del sistema.</p>
    </div>

    @if(session('success'))
        <div class="alert alert-success">{{ session('success') }}</div>
    @endif

    <div class="card admin-card shadow-sm">
        <div class="card-body">
            <form method="post" action="{{ route('admin.parametros.update') }}">
                @csrf
                @method('PUT')

                <div class="table-responsive">
                    <table class="table table-sm align-middle mb-0">
                        <thead class="table-light">
                            <tr>
                                <th>Parámetro</th>
                                <th>Clave</th>
                                <th style="width:14rem">Valor</th>
                            </tr>
                        </thead>
                        <tbody>
                            @forelse($parametros as $parametro)
                                <tr>
                                    <td>
                                        <div class="fw-semibold">{{ $parametro->nombre }}</div>
                                        @if($parametro->descripcion)
                                            <div class="text-muted small">{{ $parametro->descripcion }}</div>
                                        @endif
                                    </td>
                                    <td class="font-monospace small text-muted">{{ $parametro->clave }}</td>
                                    <td>
                                        <input type="text"
                                               name="valores[{{ $parametro->clave }}]"
                                               class="form-control form-control-sm @error('valores.'.$parametro->clave) is-invalid @enderror"
                                               value="{{ old('valores.'.$parametro->clave, $parametro->valor) }}"
                                               autocomplete="off">
                                        @error('valores.'.$parametro->clave)
                                            <div class="invalid-feedback d-block">{{ $message }}</div>
                                        @enderror
                                    </td>
                                </tr>
                            @empty
                                <tr>
                                    <td colspan="3" class="text-center text-muted py-4">No hay parámetros configurados.</td>
                                </tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>

                @if($parametros->isNotEmpty())
                    <div class="mt-3 d-flex justify-content-end">
                        <button type="submit" class="btn btn-primary">
                            <i class="bi bi-check-lg"></i> Guardar
                        </button>
                    </div>
                @endif
            </form>
        </div>
    </div>
</div>
@endsection
