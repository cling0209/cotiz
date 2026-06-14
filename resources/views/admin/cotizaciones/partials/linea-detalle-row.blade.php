@php
    $linea = $row['linea'];
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
        @if(! empty($row['image_url']))
            <button type="button"
                    class="product-image-zoom-trigger"
                    data-image-url="{{ $row['image_url'] }}"
                    data-image-title="{{ $linea->prod_item }} — {{ $row['prod_nombre'] }}"
                    title="Ver imagen ampliada">
                <x-product-image
                    :maeprod="$linea->producto"
                    :alt="$row['prod_nombre']"
                    variant="admin-thumb"
                    wrapperClass="imagen"
                />
            </button>
        @else
            <x-product-image
                :maeprod="$linea->producto"
                :alt="$row['prod_nombre']"
                variant="admin-thumb"
                wrapperClass="imagen"
            />
        @endif
    </td>
    <td>
        <div class="d-flex flex-wrap gap-1 align-items-center">
            <span class="linea-codigo-interno @if($row['pendiente_vinculo']) text-warning fw-semibold @endif">{{ $linea->prod_item }}</span>
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
    <td>
        <input type="text" name="lineas[{{ $idx }}][prod_item_softland]" maxlength="20" value="{{ old('lineas.'.$idx.'.prod_item_softland', $row['prod_item_softland']) }}" title="C&oacute;digo Softland">
    </td>
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
            <div class="linea-orden-buttons">
                <button
                    type="button"
                    class="btn btn-outline-secondary btn-sm linea-orden-subir"
                    data-prod="{{ $linea->prod_item }}"
                    data-orden="{{ $linea->orden }}"
                    title="Subir"
                    @disabled($isFirst)
                ><i class="bi bi-chevron-up"></i></button>
                <button
                    type="button"
                    class="btn btn-outline-secondary btn-sm linea-orden-bajar"
                    data-prod="{{ $linea->prod_item }}"
                    data-orden="{{ $linea->orden }}"
                    title="Bajar"
                    @disabled($isLast)
                ><i class="bi bi-chevron-down"></i></button>
            </div>
        </div>
    </td>
    <td class="text-center eliminar-cell" data-prod="{{ $linea->prod_item }}" data-orden="{{ $linea->orden }}"></td>
</tr>
