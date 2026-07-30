@props([
    'paginator',
    'entityLabel' => 'registros',
    'screenKey' => null,
])

@php
    use App\Support\ListadoPorPagina;

    /** @var \Illuminate\Contracts\Pagination\LengthAwarePaginator $paginator */
    $screenKey = $screenKey ?? request()->route()?->getName() ?? request()->path();
    $porPagina = $paginator->perPage();
    $queryParams = request()->except(['page', 'por_pagina']);
@endphp

@if($paginator->total() > 0)
    <div
        class="listado-paginacion d-flex flex-column flex-md-row align-items-md-center justify-content-md-between gap-3 py-2"
        data-listado-por-pagina
        data-screen-key="{{ $screenKey }}"
        data-por-pagina="{{ $porPagina }}"
    >
        <div class="d-flex flex-wrap align-items-center gap-3">
            <span class="text-muted small">
                Mostrando {{ $paginator->count() }} de {{ $paginator->total() }} {{ $entityLabel }}
            </span>
            <form method="get" action="{{ url()->current() }}" class="d-flex align-items-center gap-2 mb-0" data-no-loader>
                @foreach($queryParams as $name => $value)
                    @if(is_array($value))
                        @foreach($value as $item)
                            <input type="hidden" name="{{ $name }}[]" value="{{ $item }}">
                        @endforeach
                    @else
                        <input type="hidden" name="{{ $name }}" value="{{ $value }}">
                    @endif
                @endforeach
                <input type="hidden" name="page" value="1">
                <label for="por-pagina-{{ md5($screenKey) }}" class="form-label small mb-0 text-nowrap">
                    Registros por página:
                </label>
                <select
                    id="por-pagina-{{ md5($screenKey) }}"
                    name="por_pagina"
                    class="form-select form-select-sm w-auto"
                    onchange="this.form.submit()"
                >
                    @foreach(ListadoPorPagina::OPCIONES as $opcion)
                        <option value="{{ $opcion }}" @selected($porPagina === $opcion)>{{ $opcion }}</option>
                    @endforeach
                </select>
            </form>
        </div>
        @if($paginator->hasPages())
            <div class="listado-paginacion-links">
                {{ $paginator->withQueryString()->links() }}
            </div>
        @endif
    </div>
@endif
