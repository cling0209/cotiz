<?php

namespace App\Notifications;

use App\Models\Order;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class OrderConfirmationNotification extends Notification
{
    public function __construct(
        public Order $order,
        public array $payment = [],
    ) {}

    public function via($notifiable): array
    {
        return ['mail'];
    }

    public function toMail($notifiable): MailMessage
    {
        $order = $this->order->loadMissing('items');
        $appName = config('app.name', 'Tienda Rómulo');

        $message = (new MailMessage)
            ->subject('Confirmación de pedido — '.$appName)
            ->greeting('Hola, '.$order->customer_name)
            ->line('Tu pedido **#'.$order->uuid.'** fue confirmado y el pago fue recibido.')
            ->line('**Productos**');

        foreach ($order->items as $item) {
            $message->line($item->product_name.' × '.$item->quantity.' — '.clp($item->line_total));
        }

        $message->line('**Resumen**')
            ->line('Subtotal: '.clp($order->subtotal))
            ->line('Envío: '.clp($order->shipping_amount).($order->shipping_rate_label ? ' ('.$order->shipping_rate_label.')' : ''))
            ->line('**Total pagado: '.clp($order->total).'**');

        if (! empty($this->payment['payment_type_label'])) {
            $paymentLine = 'Pagado con '.$this->payment['payment_type_label'];
            if (! empty($this->payment['card_last_four'])) {
                $paymentLine .= ' · tarjeta ****'.$this->payment['card_last_four'];
            }
            if (! empty($this->payment['installments_number']) && $this->payment['installments_number'] > 0) {
                $paymentLine .= ' · '.$this->payment['installments_number'].' cuota(s)';
            }
            $message->line($paymentLine);
        }

        $address = trim(implode(' ', array_filter([
            $order->shipping_street,
            $order->shipping_street_number,
            $order->shipping_apartment ? 'Depto '.$order->shipping_apartment : null,
        ])));

        $message->line('**Dirección de envío**')
            ->line($order->shipping_recipient_name)
            ->line($order->shipping_phone)
            ->line($address)
            ->line($order->shipping_comuna.', '.$order->shipping_region)
            ->action('Seguir comprando', route('catalog'))
            ->line('Gracias por comprar en '.$appName.'.');

        return $message;
    }
}
