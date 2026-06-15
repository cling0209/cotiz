@php
    $linea = $row['linea'];
    $totalLineas = $totalLineas ?? 1;
    $mostrarSoftland = $mostrarSoftland ?? auth()->user()?->isSuperAdmin();
@endphp
<tr
    @class([
        'linea-repetida' => $row['repetidos'] > 1,
        'linea-pendiente-vinculo' => $row['pendiente_vinculo'],
    ])
    data-linea="{{ $idx }}"
    data-prod="{{ $linea->prod_item }}"
    data-orden="{{ $linea->orden }}"
    @if($row['prod_item_agile'] !== '') data-prod-item-agile="{{ $row['prod_item_agile'] }}" @endif
>
    <td class="text-center linea-drag-cell">
        <span class="linea-drag-handle" title="Arrastrar para reordenar" aria-label="Arrastrar fila">
            <svg class="linea-drag-grip" width="14" height="20" viewBox="0 0 14 20" aria-hidden="true" focusable="false">
                <circle cx="4" cy="4" r="2"/>
                <circle cx="10" cy="4" r="2"/>
                <circle cx="4" cy="10" r="2"/>
                <circle cx="10" cy="10" r="2"/>
                <circle cx="4" cy="16" r="2"/>
                <circle cx="10" cy="16" r="2"/>
            </svg>
        </span>
    </td>
    <td class="linea-imagen-cell" onclick="event.stopPropagation();">
        @php
            $lineaImagenUrl = trim((string) ($row['image_url'] ?? ''));
            $lineaImagenTitulo = \App\Support\ProductCodeNormalizer::normalize($linea->prod_item)
                . ' — '
                . ($row['prod_nombre'] ?? '');
        @endphp
        @if($lineaImagenUrl !== '')
            <button type="button"
                    class="product-image-zoom-trigger cotiz-buscar-thumb-btn"
                    data-image-url="{{ $lineaImagenUrl }}"
                    data-image-title="{{ $lineaImagenTitulo }}"
                    title="Ver imagen ampliada">
                <img src="{{ $lineaImagenUrl }}"
                     alt=""
                     class="cotiz-buscar-thumb"
                     loading="eager"
                     decoding="async"
                     referrerpolicy="no-referrer"
                     onerror="this.onerror=null;this.src='{{ asset('images/no-image.svg') }}'">
            </button>
        @else
            <img src="{{ asset('images/no-image.svg') }}"
                 alt=""
                 class="cotiz-buscar-thumb"
                 loading="eager"
                 decoding="async">
        @endif
    </td>
    <td>
        <div class="d-flex flex-wrap gap-1 align-items-center">
            <span class="linea-codigo-interno @if($row['pendiente_vinculo']) text-warning fw-semibold @endif">{{ \App\Support\ProductCodeNormalizer::normalize($linea->prod_item) }}</span>
            @if($row['prod_item_agile'] !== '')
                <button
                    type="button"
                    class="btn btn-outline-primary btn-sm btn-buscar-linea-agile text-nowrap flex-shrink-0"
                    data-fila="{{ $idx }}"
                    data-orden="{{ $linea->orden }}"
                    data-prod-item-agile="{{ $row['prod_item_agile'] }}"
                    data-descripcion-agile="{{ $row['prod_descripcion_agile'] }}"
                    title="Buscar o cambiar producto del maestro"
                >Buscar</button>
            @endif
        </div>
        <input type="hidden" name="lineas[{{ $idx }}][prod_item]" value="{{ $linea->prod_item }}">
        <input type="hidden" name="lineas[{{ $idx }}][orden]" value="{{ $linea->orden }}">
    </td>
    @if($mostrarSoftland)
    <td>
        <input type="text" name="lineas[{{ $idx }}][prod_item_softland]" maxlength="20" value="{{ old('lineas.'.$idx.'.prod_item_softland', $row['prod_item_softland']) }}" title="C&oacute;digo Softland">
    </td>
    @endif
    <td><span class="nv-fill linea-id-agile">{{ $row['prod_item_agile'] }}</span></td>
    <td>
        @if($row['prod_item_agile'] !== '' && $row['prod_descripcion_agile'] !== '')
            <span class="nv-fill linea-desc-agile small">{{ $row['prod_descripcion_agile'] }}</span>
        @else
            <span class="text-muted">—</span>
        @endif
    </td>
    <td>
        @if($row['pendiente_vinculo'])
            <span class="nv-fill linea-prod-nombre text-warning-emphasis">Sin vincular</span>
        @else
            <span class="nv-fill linea-prod-nombre">{{ $row['prod_nombre'] }}</span>
        @endif
    </td>
    <td>
        <span @class(['nv-fill', 'fecha-precio-antigua' => $row['prod_valor_fecha_antigua']])>{{ $row['prod_valor_fecha'] }}</span>
    </td>
    <td>
        <input type="number" name="lineas[{{ $idx }}][prod_valor_costo]" class="nv-precio-costo-sololectura" value="{{ old('lineas.'.$idx.'.prod_valor_costo', $linea->prod_valor_costo) }}" readonly tabindex="-1" title="Precio costo (solo lectura)">
    </td>
    <td>
        <input type="number" name="lineas[{{ $idx }}][prod_valor]" class="linea-prod-valor" min="0" value="{{ old('lineas.'.$idx.'.prod_valor', $linea->prod_valor) }}" title="Precio unitario">
    </td>
    <td>
        <input type="number" name="lineas[{{ $idx }}][cantidad]" class="linea-cantidad" min="1" value="{{ old('lineas.'.$idx.'.cantidad', $linea->cantidad) }}">
    </td>
    <td class="linea-total text-end">${{ number_format($row['total'], 0, ',', '.') }}</td>
    <td class="text-center linea-orden-cell">
        <div class="linea-orden-controls">
            <span class="linea-orden-num">{{ $linea->orden }}</span>
            <div class="linea-orden-ir-wrap">
                <input
                    type="number"
                    class="linea-orden-destino"
                    min="1"
                    max="{{ $totalLineas }}"
                    value="{{ $linea->orden }}"
                    title="Ir a posición"
                    aria-label="Posición destino"
                >
                <button
                    type="button"
                    class="btn btn-outline-primary btn-sm linea-orden-ir"
                    data-prod="{{ $linea->prod_item }}"
                    data-orden="{{ $linea->orden }}"
                    title="Ir a posición"
                >Ir</button>
            </div>
        </div>
    </td>
    @unless($desdeAdjudicadas ?? false)
    <td class="text-center eliminar-cell" data-prod="{{ $linea->prod_item }}" data-orden="{{ $linea->orden }}"></td>
    @endunless
</tr>
