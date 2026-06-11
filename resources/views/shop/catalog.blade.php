@extends('layouts.shop')

@section('title', 'Catálogo')

@section('content')
<section class="container py-4 py-lg-5">
    <div class="row g-4">
        <aside class="col-lg-3">
            <div class="checkout-card card p-3">
                <h2 class="h6 fw-bold mb-3">Categorías</h2>
                <div class="d-flex flex-column gap-2">
                    <a href="{{ route('catalog', array_filter(['q' => $search])) }}"
                       class="category-pill {{ !$activeCategory ? 'active' : '' }}">Todas</a>
                    @foreach($categories as $cat)
                        <a href="{{ route('catalog', array_filter(['category' => $cat->slug, 'q' => $search])) }}"
                           class="category-pill {{ $activeCategory === $cat->slug ? 'active' : '' }}">
                            {{ $cat->name }}
                        </a>
                    @endforeach
                </div>
            </div>
        </aside>
        <div class="col-lg-9">
            <div class="d-flex justify-content-between align-items-center mb-4">
                <h1 class="h3 fw-bold mb-0 section-title"><i class="bi bi-grid"></i> Catálogo</h1>
                @if($search)
                    <span class="text-muted">Resultados para “{{ $search }}”</span>
                @endif
            </div>
            <div class="row g-4">
                @forelse($products as $product)
                    <x-product-card :product="$product" />
                @empty
                    <div class="col-12">
                        <div class="alert alert-light border text-center py-5">
                            No encontramos productos con esos filtros.
                        </div>
                    </div>
                @endforelse
            </div>
            <div class="mt-4">{{ $products->withQueryString()->links() }}</div>
        </div>
    </div>
</section>
@endsection
