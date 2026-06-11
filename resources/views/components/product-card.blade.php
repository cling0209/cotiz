@props(['product'])

<div class="col-sm-6 col-lg-3">
    <article class="card product-card h-100 border-0 shadow-sm {{ $product->is_featured ? 'product-card--featured' : '' }}">
        <a href="{{ route('product.show', $product->slug) }}" class="text-decoration-none">
            <div class="product-img-wrap">
                <x-product-image :product="$product" variant="card" />
                @if($product->compare_at_price && $product->compare_at_price > $product->price)
                    <span class="badge sale-badge">Oferta</span>
                @endif
                @if($product->is_featured)
                    <span class="badge featured-badge"><i class="bi bi-star-fill me-1"></i>Destacado</span>
                @endif
            </div>
        </a>
        <div class="card-body d-flex flex-column">
            @if($product->category)
                <small class="fw-semibold text-uppercase" style="color: var(--shop-orange);">{{ $product->category->name }}</small>
            @endif
            <h3 class="h6 card-title mt-1 mb-2">
                <a href="{{ route('product.show', $product->slug) }}" class="text-dark text-decoration-none stretched-link-title">
                    {{ $product->name }}
                </a>
            </h3>
            <div class="mt-auto">
                <div class="d-flex align-items-baseline gap-2 mb-3">
                    <span class="price fw-bold">{{ clp($product->price) }}</span>
                    @if($product->compare_at_price && $product->compare_at_price > $product->price)
                        <span class="text-muted text-decoration-line-through small">{{ clp($product->compare_at_price) }}</span>
                    @endif
                </div>
                <form action="{{ route('cart.add') }}" method="post" class="position-relative" style="z-index:2">
                    @csrf
                    <input type="hidden" name="product_id" value="{{ $product->id }}">
                    <input type="hidden" name="quantity" value="1">
                    <button type="submit" class="btn btn-add-cart btn-sm w-100 rounded-pill" @disabled($product->stock < 1)>
                        <i class="bi bi-cart-plus"></i>
                        {{ $product->stock > 0 ? 'Agregar' : 'Sin stock' }}
                    </button>
                </form>
            </div>
        </div>
    </article>
</div>
