@extends('layouts.shop')

@section('title', 'Inicio')

@section('content')
<section class="container py-4 py-lg-5">
    <div class="hero-section p-4 p-lg-5 mb-4">
        <div class="row align-items-center position-relative" style="z-index:1">
            <div class="col-lg-7">
                <span class="badge hero-badge mb-3"><i class="bi bi-truck me-1"></i> Envío a todo Chile</span>
                <h1 class="display-5 fw-bold mb-3">Todo para tu negocio y tu hogar, en un solo lugar</h1>
                <p class="lead mb-4 opacity-90">Insumos de confianza desde 2011. Compra fácil, paga seguro con Webpay y recibe donde necesites.</p>
                <a href="{{ route('catalog') }}" class="btn btn-hero-primary btn-lg rounded-pill px-4 me-2 mb-2">
                    <i class="bi bi-grid me-1"></i> Ver catálogo
                </a>
                <a href="{{ route('cart.index') }}" class="btn btn-outline-light btn-lg rounded-pill px-4 mb-2">
                    <i class="bi bi-cart3 me-1"></i> Mi carro
                </a>
            </div>
            <div class="col-lg-5 d-none d-lg-flex justify-content-end">
                <div class="hero-icon-wrap">
                    <i class="bi bi-shop display-3"></i>
                </div>
            </div>
        </div>
    </div>

    <div class="trust-banner mb-5">
        <div class="row g-3 text-center text-lg-start">
            <div class="col-md-4">
                <div class="trust-banner__item justify-content-center justify-content-lg-start">
                    <i class="bi bi-shield-check"></i>
                    <span>Pago seguro con Webpay</span>
                </div>
            </div>
            <div class="col-md-4">
                <div class="trust-banner__item justify-content-center justify-content-lg-start">
                    <i class="bi bi-clock-history"></i>
                    <span>Desde 2011 en Chile</span>
                </div>
            </div>
            <div class="col-md-4">
                <div class="trust-banner__item justify-content-center justify-content-lg-start">
                    <i class="bi bi-box-seam"></i>
                    <span>Miles de productos disponibles</span>
                </div>
            </div>
        </div>
    </div>

    @if($categories->isNotEmpty())
    <div class="mb-5">
        <h2 class="h4 fw-bold mb-3 section-title"><i class="bi bi-tags"></i> Categorías</h2>
        <div class="d-flex flex-wrap gap-2">
            @foreach($categories as $cat)
                <a href="{{ route('catalog', ['category' => $cat->slug]) }}" class="category-pill">{{ $cat->name }}</a>
            @endforeach
        </div>
    </div>
    @endif

    <div class="d-flex justify-content-between align-items-center mb-4">
        <h2 class="h4 fw-bold mb-0 section-title"><i class="bi bi-stars"></i> Destacados</h2>
        <a href="{{ route('catalog') }}" class="text-primary text-decoration-none fw-semibold">Ver todos →</a>
    </div>
    <div class="row g-4">
        @forelse($featured as $product)
            <x-product-card :product="$product" />
        @empty
            <div class="col-12"><p class="text-muted">No hay productos destacados aún.</p></div>
        @endforelse
    </div>
</section>
@endsection
