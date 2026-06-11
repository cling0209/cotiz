@extends('layouts.shop')

@section('title', $product->name)

@section('content')
<section class="container py-4 py-lg-5">
    <nav aria-label="breadcrumb" class="mb-4">
        <ol class="breadcrumb">
            <li class="breadcrumb-item"><a href="{{ route('home') }}">Inicio</a></li>
            <li class="breadcrumb-item"><a href="{{ route('catalog') }}">Catálogo</a></li>
            <li class="breadcrumb-item active">{{ $product->name }}</li>
        </ol>
    </nav>

    <div class="row g-5">
        <div class="col-lg-6">
            <x-product-image :product="$product" variant="detail" class="product-detail-img shadow-sm" />
        </div>
        <div class="col-lg-6">
            @if($product->category)
                <span class="badge text-bg-primary mb-2">{{ $product->category->name }}</span>
            @endif
            <h1 class="h2 fw-bold mb-3">{{ $product->name }}</h1>
            <p class="text-muted">SKU: {{ $product->sku }}</p>
            <div class="d-flex align-items-baseline gap-3 mb-4">
                <span class="display-6 fw-bold text-primary">{{ clp($product->price) }}</span>
                @if($product->compare_at_price && $product->compare_at_price > $product->price)
                    <span class="text-muted text-decoration-line-through fs-5">{{ clp($product->compare_at_price) }}</span>
                @endif
            </div>
            <p class="lead text-secondary">{{ $product->description }}</p>

            @if($product->attributes->isNotEmpty())
                <ul class="list-unstyled mb-4">
                    @foreach($product->attributes as $attr)
                        <li><strong>{{ $attr->name }}:</strong> {{ $attr->value }}</li>
                    @endforeach
                </ul>
            @endif

            <p class="mb-3">
                @if($product->stock > 0)
                    <span class="badge text-bg-success"><i class="bi bi-check-circle"></i> {{ $product->stock }} disponibles</span>
                @else
                    <span class="badge text-bg-secondary">Sin stock</span>
                @endif
            </p>

            <form action="{{ route('cart.add') }}" method="post" class="row g-2 align-items-end">
                @csrf
                <input type="hidden" name="product_id" value="{{ $product->id }}">
                <div class="col-auto">
                    <label class="form-label">Cantidad</label>
                    <input type="number" name="quantity" value="1" min="1" max="{{ min(99, $product->stock) }}"
                           class="form-control" style="width:5rem" @disabled($product->stock < 1)>
                </div>
                <div class="col">
                    <button type="submit" class="btn btn-add-cart btn-lg rounded-pill w-100" @disabled($product->stock < 1)>
                        <i class="bi bi-cart-plus"></i> Agregar al carro
                    </button>
                </div>
            </form>
        </div>
    </div>

    @if($related->isNotEmpty())
        <hr class="my-5">
        <h2 class="h4 fw-bold mb-4">También te puede interesar</h2>
        <div class="row g-4">
            @foreach($related as $item)
                <x-product-card :product="$item" />
            @endforeach
        </div>
    @endif
</section>
@endsection
