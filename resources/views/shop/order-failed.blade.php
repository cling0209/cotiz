@extends('layouts.shop')

@section('title', 'Pago rechazado')

@section('content')
<section class="container py-5">
    <div class="checkout-card card text-center p-5 mx-auto" style="max-width:560px">
        <div class="text-danger display-3 mb-3"><i class="bi bi-x-circle-fill"></i></div>
        <h1 class="h3 fw-bold mb-2">Pago no completado</h1>
        @if(!empty($cancelled))
            <p class="text-muted mb-4">Cancelaste el pago del pedido <strong>#{{ $order->uuid }}</strong>. Tu carro sigue disponible.</p>
        @else
            <p class="text-muted mb-4">No se pudo procesar el pago del pedido <strong>#{{ $order->uuid }}</strong>. Tu carro sigue disponible.</p>
        @endif
        <a href="{{ route('checkout.webpay.retry', $order->uuid) }}" class="btn btn-primary rounded-pill me-2">Reintentar pago con Webpay</a>
        <a href="{{ route('cart.index') }}" class="btn btn-outline-primary rounded-pill me-2">Ver mi carro</a>
        <a href="{{ route('catalog') }}" class="btn btn-outline-secondary rounded-pill">Volver al catálogo</a>
    </div>
</section>
@endsection
