<?php

namespace App\Services;

use App\Models\Order;
use App\Notifications\OrderConfirmationNotification;
use Illuminate\Support\Facades\Notification;

class OrderConfirmationMailer
{
    public function send(Order $order, array $payment = []): void
    {
        if (! $order->customer_email) {
            return;
        }

        Notification::route('mail', $order->customer_email)
            ->notify(new OrderConfirmationNotification($order, $payment));
    }
}
