@extends('layouts.admin')

@section('title', 'Palabras clave')

@section('content')
@php
    $filtros = $filtros ?? ['orden_campo' => 'frase', 'orden_dir' => 'ASC'];
    $sortLink = fn (string $campo, string $dir) => route(
        'admin.oportunidades.palabras-clave.index',
        array_merge($filtros, ['orden_campo' => $campo, 'orden_dir' => $dir])
    );
@endphp
<div class="container-fluid py-4">
    <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-4">
        <div>
            <h1 class="h3 mb-1">Palabras clave</h1>
            <p class="text-muted mb-0 small">
                Temas que usa este sitio para encontrar oportunidades publicadas en Compra &Aacute;gil
                (solo con <code>MERCADOPUBLICO_ANALISIS_ADMIN=true</code>).
                Las cotizaciones encontradas se sincronizan al sitio par; las palabras clave no.
            </p>
        </div>
        <a href="{{ route('admin.oportunidades.para-cotizar.index') }}" class="btn btn-outline-primary btn-sm">
            <i class="bi bi-lightning-charge"></i> Ver oportunidades
        </a>
    </div>

    <div class="alert alert-info py-2 small" role="status">
        <i class="bi bi-info-circle"></i>
        Por defecto la lista va en <strong>orden alfab&eacute;tico</strong>.
        Puede ordenar por columna con las flechas.
        La b&uacute;squeda de oportunidades hace match con <strong>todas</strong> las palabras en cada regi&oacute;n.
    </div>

    <div class="card shadow-sm mb-4">
        <div class="card-body">
            <form method="post" action="{{ route('admin.oportunidades.palabras-clave.store') }}" class="row g-2 align-items-end">
                @csrf
                <div class="col-md-8 col-lg-6">
                    <label class="form-label small mb-1" for="frase">Nueva palabra clave</label>
                    <input type="text" name="frase" id="frase" class="form-control form-control-sm @error('frase') is-invalid @enderror"
                           maxlength="200" required placeholder="Ej: servicio de aseo, alimentaci&oacute;n..."
                           value="{{ old('frase') }}">
                    @error('frase')
                        <div class="invalid-feedback">{{ $message }}</div>
                    @enderror
                </div>
                <div class="col-auto">
                    <button type="submit" class="btn btn-primary btn-sm">
                        <i class="bi bi-plus-lg"></i> Agregar
                    </button>
                </div>
            </form>
        </div>
    </div>

    <div class="card shadow-sm">
        <div class="table-responsive">
            <table class="table table-sm table-hover mb-0 align-middle">
                <thead class="table-light">
                    <tr>
                        <th style="width:3.5rem;" class="text-center">#</th>
                        <th>
                            Palabra clave
                            <a href="{{ $sortLink('frase', 'ASC') }}" class="text-muted small text-decoration-none" title="A → Z">&#9650;</a>
                            <a href="{{ $sortLink('frase', 'DESC') }}" class="text-muted small text-decoration-none" title="Z → A">&#9660;</a>
                        </th>
                        <th>
                            Agregada por
                            <a href="{{ $sortLink('creador', 'ASC') }}" class="text-muted small text-decoration-none" title="A → Z">&#9650;</a>
                            <a href="{{ $sortLink('creador', 'DESC') }}" class="text-muted small text-decoration-none" title="Z → A">&#9660;</a>
                        </th>
                        <th>
                            Fecha
                            <a href="{{ $sortLink('fecha', 'ASC') }}" class="text-muted small text-decoration-none" title="Más antigua">&#9650;</a>
                            <a href="{{ $sortLink('fecha', 'DESC') }}" class="text-muted small text-decoration-none" title="Más reciente">&#9660;</a>
                        </th>
                        <th></th>
                    </tr>
                </thead>
                <tbody>
                    @forelse($palabras as $index => $palabra)
                        <tr>
                            <td class="text-center tabular-nums small text-muted">{{ $index + 1 }}</td>
                            <td class="fw-medium">{{ $palabra->frase }}</td>
                            <td class="small text-muted">
                                {{ $palabra->creador?->fullName() ?: ($palabra->creador?->username ?: '—') }}
                            </td>
                            <td class="small text-muted tabular-nums">
                                {{ $palabra->created_at?->timezone(config('app.timezone'))->format('d/m/Y H:i') }}
                            </td>
                            <td class="text-end">
                                <form method="post"
                                      action="{{ route('admin.oportunidades.palabras-clave.destroy', $palabra) }}"
                                      class="d-inline"
                                      data-confirm="¿Eliminar la palabra clave «{{ $palabra->frase }}»?">
                                    @csrf
                                    @method('DELETE')
                                    <button type="submit" class="btn btn-outline-danger btn-sm py-0">Eliminar</button>
                                </form>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="5" class="text-center text-muted py-4">
                                A&uacute;n no hay palabras clave. Agregue al menos una para buscar oportunidades.
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>
</div>
@endsection
