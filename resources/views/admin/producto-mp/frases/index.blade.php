@extends('layouts.admin')

@section('title', 'Palabras clave MP')

@section('content')
@php
    $filtros = $filtros ?? ['orden_campo' => 'frase', 'orden_dir' => 'ASC'];
    $regionesDisponibles = $regionesDisponibles ?? [];
    $sortLink = fn (string $campo, string $dir) => route(
        'admin.producto-mp.frases.index',
        array_merge($filtros, ['orden_campo' => $campo, 'orden_dir' => $dir])
    );
@endphp
<div class="container-fluid py-4">
    <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-4">
        <div>
            <h1 class="h3 mb-1">Palabras clave MP</h1>
            <p class="text-muted mb-0 small">
                Textos para encontrar <strong>&iacute;tems de producto</strong> en Compra &Aacute;gil
                (nombre y descripci&oacute;n de la l&iacute;nea; no el t&iacute;tulo de la CA).
                No se usan para vincular Agile ni para oportunidades.
            </p>
        </div>
        @if($puedeBuscarMp ?? false)
        <a href="{{ route('admin.producto-mp.encontrados.index') }}" class="btn btn-outline-primary btn-sm">
            <i class="bi bi-lightning-charge"></i> Ver productos MP
        </a>
        @endif
    </div>

    <div class="alert alert-info py-2 small" role="status">
        <i class="bi bi-info-circle"></i>
        Por defecto la lista va en <strong>orden alfab&eacute;tico</strong>.
        Puede ordenar por columna con las flechas.
        Si una palabra clave <strong>no tiene regiones</strong>, se busca en <strong>todas</strong> las de
        <code>MERCADOPUBLICO_REGIONES</code>; con regiones seleccionadas, solo en esas.
    </div>

    <div class="card shadow-sm mb-4">
        <div class="card-body">
            <form method="post" action="{{ route('admin.producto-mp.frases.store') }}" class="row g-3">
                @csrf
                <div class="col-md-8 col-lg-5">
                    <label class="form-label small mb-1" for="frase">Nueva palabra clave</label>
                    <input type="text" name="frase" id="frase" class="form-control form-control-sm @error('frase') is-invalid @enderror"
                           maxlength="200" required placeholder="Ej: barra pritt, papel oficio 75..."
                           value="{{ old('frase') }}">
                    @error('frase')
                        <div class="invalid-feedback">{{ $message }}</div>
                    @enderror
                </div>
                <div class="col-md-12 col-lg-5">
                    <label class="form-label small mb-1">Regiones (opcional)</label>
                    <div class="border rounded p-2 bg-light" style="max-height: 8rem; overflow-y: auto;">
                        @forelse($regionesDisponibles as $codigo => $nombre)
                            <div class="form-check form-check-inline me-3 mb-1">
                                <input class="form-check-input" type="checkbox" name="regiones[]"
                                       id="region-nueva-{{ $codigo }}" value="{{ $codigo }}"
                                       @checked(is_array(old('regiones')) && in_array((string) $codigo, old('regiones', []), true))>
                                <label class="form-check-label small" for="region-nueva-{{ $codigo }}">{{ $nombre }}</label>
                            </div>
                        @empty
                            <span class="small text-muted">No hay regiones en MERCADOPUBLICO_REGIONES.</span>
                        @endforelse
                    </div>
                    <div class="form-text">Vac&iacute;o = buscar en todas las regiones.</div>
                </div>
                <div class="col-auto d-flex align-items-end">
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
                        <th>Regiones</th>
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
                        @php
                            $codigosPalabra = $palabra->codigosRegion();
                            $editId = 'regiones-edit-'.$palabra->id;
                        @endphp
                        <tr>
                            <td class="text-center tabular-nums small text-muted">{{ $index + 1 }}</td>
                            <td class="fw-medium">{{ $palabra->frase }}</td>
                            <td class="small">
                                @if($palabra->aplicaATodasLasRegiones())
                                    <span class="badge text-bg-secondary">Todas</span>
                                @else
                                    <span class="text-muted">{{ $palabra->etiquetaRegiones() }}</span>
                                @endif
                                <button type="button" class="btn btn-link btn-sm p-0 ms-1 align-baseline"
                                        data-bs-toggle="collapse" data-bs-target="#{{ $editId }}"
                                        aria-expanded="false" aria-controls="{{ $editId }}">
                                    Editar
                                </button>
                            </td>
                            <td class="small text-muted">
                                {{ $palabra->creador?->fullName() ?: ($palabra->creador?->username ?: '—') }}
                            </td>
                            <td class="small text-muted tabular-nums">
                                {{ $palabra->created_at?->timezone(config('app.timezone'))->format('d/m/Y H:i') }}
                            </td>
                            <td class="text-end">
                                <form method="post"
                                      action="{{ route('admin.producto-mp.frases.destroy', $palabra) }}"
                                      class="d-inline"
                                      data-confirm="¿Eliminar la palabra clave «{{ $palabra->frase }}»?">
                                    @csrf
                                    @method('DELETE')
                                    <button type="submit" class="btn btn-outline-danger btn-sm py-0">Eliminar</button>
                                </form>
                            </td>
                        </tr>
                        <tr class="collapse" id="{{ $editId }}">
                            <td colspan="6" class="bg-light border-top-0 pt-0">
                                <form method="post" action="{{ route('admin.producto-mp.frases.update', $palabra) }}"
                                      class="row g-2 align-items-end py-2">
                                    @csrf
                                    @method('PUT')
                                    <div class="col-12">
                                        <span class="small fw-medium">Regiones para «{{ $palabra->frase }}»</span>
                                        <span class="small text-muted ms-1">(ninguna = todas)</span>
                                    </div>
                                    <div class="col-12">
                                        <div class="border rounded p-2 bg-white" style="max-height: 7rem; overflow-y: auto;">
                                            @foreach($regionesDisponibles as $codigo => $nombre)
                                                <div class="form-check form-check-inline me-3 mb-1">
                                                    <input class="form-check-input" type="checkbox" name="regiones[]"
                                                           id="region-{{ $palabra->id }}-{{ $codigo }}" value="{{ $codigo }}"
                                                           @checked(in_array((int) $codigo, $codigosPalabra, true))>
                                                    <label class="form-check-label small" for="region-{{ $palabra->id }}-{{ $codigo }}">{{ $nombre }}</label>
                                                </div>
                                            @endforeach
                                        </div>
                                    </div>
                                    <div class="col-auto">
                                        <button type="submit" class="btn btn-primary btn-sm">Guardar regiones</button>
                                    </div>
                                </form>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="6" class="text-center text-muted py-4">
                                A&uacute;n no hay palabras clave. Agregue al menos una para buscar productos MP.
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>
</div>
@endsection
