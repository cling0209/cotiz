@props([
    'product' => null,
    'src' => null,
    'alt' => '',
    'class' => '',
    'wrapperClass' => '',
    'variant' => 'card',
])

@php
    $imageSrc = $src ?? ($product ? product_image($product) : '');
    $altText = $alt ?: ($product?->name ?? 'Producto');
    $variantClass = 'product-image--' . $variant;
    $hasSrc = filled($imageSrc);
@endphp

<div @class(['product-image', $variantClass, $wrapperClass, 'is-error' => ! $hasSrc]) data-product-image>
    <div class="product-image__state product-image__state--loading" aria-hidden="true">
        <img src="{{ asset('images/loading.svg') }}" alt="" class="product-image__state-icon" width="48" height="48">
        <span class="product-image__state-label">Cargando...</span>
    </div>
    <div class="product-image__state product-image__state--empty" aria-hidden="true">
        <img src="{{ asset('images/no-image.svg') }}" alt="" class="product-image__state-icon">
        <span class="product-image__state-label">Sin imagen</span>
    </div>
    @if($hasSrc)
        <img
            src="{{ $imageSrc }}"
            alt="{{ $altText }}"
            class="product-image__img {{ $class }}"
            loading="lazy"
            decoding="async"
        >
    @endif
</div>
