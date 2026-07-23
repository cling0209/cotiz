@php
    /** @var string $col */
    /** @var string $label */
    /** @var string $route */
    $align = $align ?? '';
    $actual = $filtros['sort'] ?? 'fecha_ultimo_cambio';
    $dir = strtolower((string) ($filtros['dir'] ?? 'desc')) === 'asc' ? 'asc' : 'desc';
    $next = ($actual === $col && $dir === 'asc') ? 'desc' : 'asc';
    $qs = array_merge(request()->except('page'), ['sort' => $col, 'dir' => $next]);
    $icon = $actual === $col
        ? ($dir === 'asc' ? 'bi-sort-up' : 'bi-sort-down')
        : 'bi-arrow-down-up';
    $ariaSort = $actual === $col
        ? ($dir === 'asc' ? 'ascending' : 'descending')
        : 'none';
@endphp
<th @class(['text-nowrap', $align => $align !== '']) aria-sort="{{ $ariaSort }}">
    <a href="{{ route($route, $qs) }}" class="text-decoration-none text-reset" data-no-loader title="Ordenar por {{ $label }}">
        {{ $label }} <i class="bi {{ $icon }} opacity-75 small" aria-hidden="true"></i>
    </a>
</th>
