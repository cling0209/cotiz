@props([
    'product' => null,
    'maeprod' => null,
    'src' => null,
    'alt' => '',
    'class' => '',
    'wrapperClass' => '',
    'variant' => 'card',
])

@php
    $imageSrc = $src ?? ($maeprod
        ? maeprod_image($maeprod)
        : ($product ? product_image($product) : ''));
    $altText = $alt ?: ($product?->name ?? $maeprod?->prod_nombre ?? 'Producto');
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
            @if($maeprod && count($maeprod->imageUrlCandidates()) > 1)
                data-image-fallbacks="{{ json_encode(array_slice($maeprod->imageUrlCandidates(), 1)) }}"
            @endif
            alt="{{ $altText }}"
            class="product-image__img {{ $class }}"
            loading="{{ $variant === 'admin-preview' || $variant === 'detail' ? 'eager' : 'lazy' }}"
            decoding="async"
            referrerpolicy="no-referrer"
        >
    @endif
</div>
