<?php

use App\Models\Maeprod;
use App\Models\Product;

if (! function_exists('clp')) {
    function clp(float|int|string|null $amount): string
    {
        return '$'.number_format((float) $amount, 0, ',', '.');
    }
}

if (! function_exists('product_image')) {
    function product_image(?Product $product): string
    {
        if (! $product) {
            return '';
        }

        return $product->resolveImageUrl();
    }
}

if (! function_exists('maeprod_image')) {
    function maeprod_image(?Maeprod $product): string
    {
        if (! $product) {
            return '';
        }

        return $product->resolveImageUrl();
    }
}

if (! function_exists('product_image_loading')) {
    function product_image_loading(): string
    {
        return asset('images/loading.svg');
    }
}

if (! function_exists('product_image_placeholder')) {
    function product_image_placeholder(): string
    {
        return asset('images/no-image.svg');
    }
}

if (! function_exists('order_status_label')) {
    function order_status_label(?string $status): string
    {
        return match ($status) {
            'pending_payment' => 'Pendiente de pago',
            'paid' => 'Pagado',
            'processing' => 'En preparación',
            'shipped' => 'Enviado',
            'delivered' => 'Entregado',
            'cancelled' => 'Cancelado',
            'payment_failed' => 'Pago fallido',
            default => $status ?? '—',
        };
    }
}

if (! function_exists('payment_status_label')) {
    function payment_status_label(?string $status): string
    {
        return match ($status) {
            'pending' => 'Pendiente',
            'paid' => 'Pagado',
            'failed' => 'Fallido',
            'refunded' => 'Reembolsado',
            default => $status ?? '—',
        };
    }
}

if (! function_exists('payment_transaction_status_label')) {
    function payment_transaction_status_label(?string $status): string
    {
        return match ($status) {
            'created' => 'Iniciada',
            'approved' => 'Aprobada',
            'rejected' => 'Rechazada',
            default => $status ?? '—',
        };
    }
}
