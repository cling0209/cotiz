@props(['href' => null, 'variant' => 'dark'])

@php
    $textColor = $variant === 'light' ? '#ffffff' : '#0f172a';
    $tag = $href ? 'a' : 'span';
@endphp

<{{ $tag }}
    @if ($href) href="{{ $href }}" @endif
    {{ $attributes->merge(['class' => 'shop-brand d-inline-flex align-items-center gap-2 text-decoration-none']) }}
    aria-label="{{ config('app.name', 'Rómulo') }}"
>
    <svg class="shop-brand__icon flex-shrink-0" width="40" height="40" viewBox="0 0 40 40" role="img" aria-hidden="true">
        <defs>
            <linearGradient id="romuloGrad" x1="0%" y1="0%" x2="100%" y2="100%">
                <stop offset="0%" stop-color="#1e3a8a"/>
                <stop offset="50%" stop-color="#2563eb"/>
                <stop offset="100%" stop-color="#3b82f6"/>
            </linearGradient>
        </defs>
        <rect width="40" height="40" rx="10" fill="url(#romuloGrad)"/>
        <text x="11" y="29" font-family="'Plus Jakarta Sans', system-ui, sans-serif" font-size="22" font-weight="800" fill="#ffffff">R</text>
        <circle cx="32" cy="10" r="5" fill="#f59e0b"/>
    </svg>
    <span class="shop-brand__text fw-bold mb-0" style="color: {{ $textColor }};">{{ config('app.name', 'Rómulo') }}</span>
</{{ $tag }}>
