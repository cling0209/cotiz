@extends('layouts.shop')

@section('title', 'Pedido confirmado')

@section('content')
<section class="container py-5">
    <div class="checkout-card card text-center p-5 mx-auto" style="max-width:560px">
        <div class="text-success display-3 mb-3"><i class="bi bi-check-circle-fill"></i></div>
        <h1 class="h3 fw-bold mb-2">¡Pago exitoso!</h1>
        <p class="text-muted mb-4">Tu pedido <strong>#{{ $order->uuid }}</strong> fue confirmado.</p>
        @if(!empty($payment['payment_type_label']))
            <p class="small text-muted mb-4">
                Pagado con {{ $payment['payment_type_label'] }}
                @if(!empty($payment['card_last_four']))
                    · tarjeta ****{{ $payment['card_last_four'] }}
                @endif
                @if(!empty($payment['installments_number']) && $payment['installments_number'] > 0)
                    · {{ $payment['installments_number'] }} cuota(s)
                @endif
            </p>
        @endif
        <div class="text-start bg-light rounded-3 p-3 mb-4">
            @foreach($order->items as $item)
                <div class="d-flex justify-content-between py-1">
                    <span>{{ $item->product_name }} × {{ $item->quantity }}</span>
                    <span>{{ clp($item->line_total) }}</span>
                </div>
            @endforeach
            <div class="d-flex justify-content-between py-1 border-top mt-2 pt-2">
                <span>Subtotal</span>
                <span>{{ clp($order->subtotal) }}</span>
            </div>
            <div class="d-flex justify-content-between py-1 text-muted small">
                <span>
                    Envío
                    @if($order->shipping_rate_label)
                        <span class="text-muted">({{ $order->shipping_rate_label }})</span>
                    @endif
                </span>
                <span>{{ clp($order->shipping_amount) }}</span>
            </div>
            <hr>
            <div class="d-flex justify-content-between fw-bold">
                <span>Total pagado</span>
                <span class="text-primary">{{ clp($order->total) }}</span>
            </div>
        </div>
        <p class="small text-muted">Te enviamos un correo de confirmación a {{ $order->customer_email }}.</p>
        <a href="{{ route('catalog') }}" class="btn btn-primary rounded-pill">Seguir comprando</a>
    </div>
</section>
@endsection
