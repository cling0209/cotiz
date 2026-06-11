@extends('layouts.shop')

@section('title', 'Mi carro')

@section('content')
<section class="container py-4 py-lg-5">
    <h1 class="h3 fw-bold mb-4">Mi carro de compras</h1>

    <div id="js-cart-stock-notice" class="alert alert-warning d-none" role="alert"></div>

    @if($formatted['item_count'] === 0)
        <div class="checkout-card card text-center py-5">
            <div class="card-body">
                <i class="bi bi-cart-x display-4 text-muted mb-3"></i>
                <p class="lead text-muted">Tu carro está vacío</p>
                <a href="{{ route('catalog') }}" class="btn btn-primary rounded-pill">Ir al catálogo</a>
            </div>
        </div>
    @else
        <div class="row g-4">
            <div class="col-lg-8">
                <div class="checkout-card card">
                    <div class="table-responsive">
                        <table class="table align-middle mb-0 cart-table">
                            <thead class="table-light">
                                <tr>
                                    <th>Producto</th>
                                    <th>Precio</th>
                                    <th>Cantidad</th>
                                    <th>Subtotal</th>
                                    <th></th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach($formatted['items'] as $item)
                                    @php
                                        $stock = (int) data_get($item, 'product.stock', 0);
                                        $maxQty = min(99, max(1, $stock));
                                    @endphp
                                    <tr data-cart-row
                                        data-unit-price="{{ $item['unit_price'] }}"
                                        data-stock="{{ $stock }}"
                                        data-product-name="{{ data_get($item, 'product.name') }}">
                                        <td>
                                            <div class="d-flex align-items-center gap-3">
                                                <x-product-image
                                                    :src="data_get($item, 'product.image', '')"
                                                    :alt="data_get($item, 'product.name', 'Producto')"
                                                    variant="thumb"
                                                />
                                                <div>
                                                    <a href="{{ route('product.show', data_get($item, 'product.slug')) }}" class="fw-semibold text-decoration-none">
                                                        {{ data_get($item, 'product.name') }}
                                                    </a>
                                                    @if($stock > 0)
                                                        <div class="small text-muted">{{ $stock }} disponible{{ $stock === 1 ? '' : 's' }}</div>
                                                    @else
                                                        <div class="small text-danger">Sin stock</div>
                                                    @endif
                                                </div>
                                            </div>
                                        </td>
                                        <td>{{ clp($item['unit_price']) }}</td>
                                        <td>
                                            <input type="number"
                                                   name="items[{{ $item['id'] }}]"
                                                   value="{{ $item['quantity'] }}"
                                                   min="1"
                                                   max="{{ $maxQty }}"
                                                   form="js-cart-sync-form"
                                                   class="form-control form-control-sm js-cart-quantity"
                                                   style="width:4rem"
                                                   aria-label="Cantidad"
                                                   @disabled($stock < 1)>
                                        </td>
                                        <td class="fw-semibold js-cart-line-total">{{ clp($item['line_total']) }}</td>
                                        <td>
                                            <form action="{{ route('cart.remove', $item['id']) }}" method="post">
                                                @csrf
                                                @method('DELETE')
                                                <button class="btn btn-sm btn-outline-danger" type="submit"><i class="bi bi-trash"></i></button>
                                            </form>
                                        </td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
            <div class="col-lg-4">
                <div class="checkout-card card p-4">
                    <h2 class="h5 fw-bold mb-3">Resumen</h2>
                    <div class="d-flex justify-content-between mb-2">
                        <span>Subtotal (<span class="js-cart-item-count">{{ $formatted['item_count'] }} ítems</span>)</span>
                        <span class="fw-semibold js-cart-subtotal">{{ clp($formatted['subtotal']) }}</span>
                    </div>
                    <p class="text-muted small">El envío se calcula al pagar según tu región. Las cantidades se guardan al continuar.</p>
                    <hr>
                    <div class="d-flex justify-content-between mb-4 fs-5">
                        <span class="fw-bold">Subtotal</span>
                        <span class="fw-bold text-primary js-cart-subtotal">{{ clp($formatted['subtotal']) }}</span>
                    </div>
                    <form id="js-cart-sync-form" method="post" action="{{ route('cart.sync') }}">
                        @csrf
                        <button type="submit" class="btn btn-go-checkout btn-lg rounded-pill w-100">
                            Ir a pagar <i class="bi bi-arrow-right"></i>
                        </button>
                    </form>
                    <a href="{{ route('catalog') }}" class="btn btn-link w-100 mt-2">Seguir comprando</a>
                </div>
            </div>
        </div>
    @endif
</section>
@endsection

@push('scripts')
<script src="{{ asset('js/cart.js') }}" defer></script>
@endpush
