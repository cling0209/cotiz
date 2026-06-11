@extends('layouts.admin')

@section('title', 'Detalle venta')

@section('content')
<div class="container-fluid py-4">
    <div class="mb-4">
        <a href="{{ route('admin.orders.index') }}" class="text-decoration-none small">
            <i class="bi bi-arrow-left"></i> Volver a ventas
        </a>
        <h1 class="h3 fw-bold mt-2">Pedido #{{ substr($order->uuid, 0, 8) }}</h1>
        <p class="text-muted mb-0">{{ $order->created_at?->format('d/m/Y H:i') }} · {{ order_status_label($order->status) }}</p>
    </div>

    <div class="row g-4">
        <div class="col-lg-8">
            <div class="card admin-card mb-4">
                <div class="card-header bg-white fw-semibold">Productos</div>
                <div class="table-responsive">
                    <table class="table mb-0">
                        <thead>
                            <tr>
                                <th>Producto</th>
                                <th>SKU</th>
                                <th class="text-center">Cant.</th>
                                <th class="text-end">Precio</th>
                                <th class="text-end">Subtotal</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach($order->items as $item)
                                <tr>
                                    <td>{{ $item->product_name }}</td>
                                    <td><code>{{ $item->product_sku }}</code></td>
                                    <td class="text-center">{{ $item->quantity }}</td>
                                    <td class="text-end">{{ clp($item->unit_price) }}</td>
                                    <td class="text-end">{{ clp($item->line_total) }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                        <tfoot>
                            <tr>
                                <th colspan="4" class="text-end">Subtotal</th>
                                <th class="text-end">{{ clp($order->subtotal) }}</th>
                            </tr>
                            <tr>
                                <th colspan="4" class="text-end">Envío</th>
                                <th class="text-end">{{ clp($order->shipping_amount) }}</th>
                            </tr>
                            <tr>
                                <th colspan="4" class="text-end">Total</th>
                                <th class="text-end text-primary fs-5">{{ clp($order->total) }}</th>
                            </tr>
                        </tfoot>
                    </table>
                </div>
            </div>

            @if($order->statusHistory->isNotEmpty())
                <div class="card admin-card">
                    <div class="card-header bg-white fw-semibold">Historial</div>
                    <ul class="list-group list-group-flush">
                        @foreach($order->statusHistory as $history)
                            <li class="list-group-item small">
                                <strong>{{ $history->created_at?->format('d/m/Y H:i') }}</strong>
                                — {{ order_status_label($history->from_status) }}
                                → {{ order_status_label($history->to_status) }}
                                @if($history->note)
                                    <span class="text-muted">({{ $history->note }})</span>
                                @endif
                            </li>
                        @endforeach
                    </ul>
                </div>
            @endif
        </div>

        <div class="col-lg-4">
            <div class="card admin-card mb-4">
                <div class="card-header bg-white fw-semibold">Cliente</div>
                <div class="card-body">
                    <p class="mb-1"><strong>{{ $order->customer_name }}</strong></p>
                    <p class="mb-1"><a href="mailto:{{ $order->customer_email }}">{{ $order->customer_email }}</a></p>
                    <p class="mb-0 text-muted small">UUID: <code>{{ $order->uuid }}</code></p>
                </div>
            </div>

            <div class="card admin-card mb-4">
                <div class="card-header bg-white fw-semibold">Envío</div>
                <div class="card-body small">
                    <p class="mb-1">{{ $order->shipping_recipient_name }}</p>
                    <p class="mb-1">{{ $order->shipping_phone }}</p>
                    <p class="mb-3">
                        {{ $order->shipping_street }} {{ $order->shipping_street_number }}<br>
                        @if($order->shipping_apartment) Depto {{ $order->shipping_apartment }}<br>@endif
                        {{ $order->shipping_comuna }}, {{ $order->shipping_region }}
                    </p>
                    @if($order->shipping_rate_label)
                        <hr class="my-2">
                        <p class="mb-1"><strong>Tarifa:</strong> {{ $order->shipping_rate_label }}</p>
                        @if($order->shipping_zone === 'rm')
                            <p class="mb-1 text-muted">Zona: Región Metropolitana (tarifa fija)</p>
                        @elseif($order->shipping_zone === 'regions')
                            <p class="mb-1 text-muted">
                                Zona: Regiones · Peso total: {{ number_format($order->shipping_total_weight_kg, 2, ',', '.') }} kg
                            </p>
                            @if(is_array($order->shipping_metadata))
                                @if(isset($order->shipping_metadata['region_flat_rate']))
                                    <p class="mb-1 text-muted">
                                        @if(!empty($order->shipping_metadata['comuna']))
                                            {{ $order->shipping_metadata['comuna'] }}:
                                        @endif
                                        fija región {{ clp($order->shipping_metadata['region_flat_rate']) }}
                                        @if(isset($order->shipping_metadata['weight_tramo_amount']))
                                            + @if(!empty($order->shipping_metadata['used_fallback_additional']))adicional fijo @else tramo @endif
                                            {{ clp($order->shipping_metadata['weight_tramo_amount']) }}
                                        @endif
                                    </p>
                                @endif
                            @endif
                        @endif
                        <p class="mb-0"><strong>Costo envío:</strong> {{ clp($order->shipping_amount) }}</p>
                    @endif
                </div>
            </div>

            <div class="card admin-card">
                <div class="card-header bg-white fw-semibold">Pago</div>
                <div class="card-body">
                    <p class="mb-2">
                        <span class="badge {{ $order->payment_status === 'paid' ? 'text-bg-success' : 'text-bg-secondary' }}">
                            {{ payment_status_label($order->payment_status) }}
                        </span>
                        @if($order->payment_method)
                            <span class="badge text-bg-light border">{{ strtoupper($order->payment_method) }}</span>
                        @endif
                    </p>
                    @forelse($order->paymentTransactions as $payment)
                        <div class="border rounded p-3 small mb-2">
                            <div><strong>Estado:</strong> {{ payment_transaction_status_label($payment->status) }}</div>
                            @if($payment->payment_type_label)
                                <div><strong>Tarjeta:</strong> {{ $payment->payment_type_label }}</div>
                            @endif
                            @if($payment->card_last_four)
                                <div><strong>Últimos 4:</strong> ****{{ $payment->card_last_four }}</div>
                            @endif
                            @if($payment->installments_number)
                                <div><strong>Cuotas:</strong> {{ $payment->installments_number }}</div>
                            @endif
                            <div><strong>Monto:</strong> {{ clp($payment->amount) }}</div>
                            <div class="text-muted">{{ $payment->created_at?->format('d/m/Y H:i') }}</div>
                        </div>
                    @empty
                        <p class="text-muted small mb-0">Sin transacciones registradas.</p>
                    @endforelse
                </div>
            </div>
        </div>
    </div>
</div>
@endsection
