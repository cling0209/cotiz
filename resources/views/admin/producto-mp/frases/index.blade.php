@extends('layouts.admin')

@section('title', 'Frases búsqueda MP')

@section('content')
<div class="container-fluid py-4">
    <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-4">
        <div>
            <h1 class="h3 mb-1">Frases de búsqueda MP</h1>
            <p class="text-muted mb-0 small">
                Textos por producto para <strong>encontrar ítems</strong> en Compra Ágil.
                No se usan para vincular (eso sigue en la ficha del producto, frases Agile)
                ni para oportunidades (palabras clave).
            </p>
        </div>
        <div class="d-flex flex-wrap gap-2">
            @if($puedeBuscarMp ?? false)
            <a href="{{ route('admin.producto-mp.encontrados.index') }}" class="btn btn-outline-primary btn-sm">
                <i class="bi bi-box-seam"></i> Ver productos MP
            </a>
            @endif
        </div>
    </div>

    <div class="card shadow-sm mb-4">
        <div class="card-body">
            <form method="post" action="{{ route('admin.producto-mp.frases.store') }}" class="row g-3">
                @csrf
                <div class="col-md-3">
                    <label class="form-label small mb-1" for="prod_item">Código producto</label>
                    <input type="text" name="prod_item" id="prod_item"
                           class="form-control form-control-sm @error('prod_item') is-invalid @enderror"
                           maxlength="50" required placeholder="Ej: DEMO003"
                           value="{{ old('prod_item') }}">
                    @error('prod_item')
                        <div class="invalid-feedback">{{ $message }}</div>
                    @enderror
                </div>
                <div class="col-md-6">
                    <label class="form-label small mb-1" for="frase">Frase de búsqueda</label>
                    <input type="text" name="frase" id="frase"
                           class="form-control form-control-sm @error('frase') is-invalid @enderror"
                           maxlength="200" required placeholder="Ej: barra pritt, papel oficio 75"
                           value="{{ old('frase') }}">
                    @error('frase')
                        <div class="invalid-feedback">{{ $message }}</div>
                    @enderror
                </div>
                <div class="col-auto d-flex align-items-end">
                    <button type="submit" class="btn btn-primary btn-sm">
                        <i class="bi bi-plus-lg"></i> Agregar
                    </button>
                </div>
            </form>
        </div>
    </div>

    <form method="get" class="row g-2 mb-3" data-no-loader>
        <div class="col-md-4">
            <input type="text" name="q" value="{{ $filtros['q'] ?? '' }}" class="form-control form-control-sm"
                   placeholder="Buscar frase, código o nombre">
        </div>
        <div class="col-auto">
            <button type="submit" class="btn btn-outline-secondary btn-sm">Filtrar</button>
        </div>
    </form>

    <div class="card shadow-sm">
        <div class="table-responsive">
            <table class="table table-sm table-hover mb-0 align-middle">
                <thead class="table-light">
                    <tr>
                        <th>Producto</th>
                        <th>Nombre</th>
                        <th>Frase de búsqueda</th>
                        <th style="width:5rem;"></th>
                    </tr>
                </thead>
                <tbody>
                    @forelse($frases as $frase)
                        <tr>
                            <td class="tabular-nums">
                                <a href="{{ route('admin.productos.edit', $frase->prod_item) }}">{{ $frase->prod_item }}</a>
                            </td>
                            <td class="small">{{ $frase->producto?->prod_nombre }}</td>
                            <td>{{ $frase->frase }}</td>
                            <td class="text-end">
                                <form method="post" action="{{ route('admin.producto-mp.frases.destroy', $frase) }}"
                                      onsubmit="return confirm('¿Eliminar esta frase de búsqueda?');">
                                    @csrf
                                    <button type="submit" class="btn btn-outline-danger btn-sm py-0 px-2" title="Eliminar">&times;</button>
                                </form>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="4" class="text-center text-muted py-4">
                                Aún no hay frases de búsqueda MP.
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        @if($frases->total() > 0)
            <div class="card-footer bg-white">
                <x-listado-paginacion :paginator="$frases" entity-label="frases" screen-key="producto-mp-frases" />
            </div>
        @endif
    </div>
</div>
@endsection
