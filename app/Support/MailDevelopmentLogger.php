<?php

namespace App\Support;

use Illuminate\Mail\Events\MessageSending;
use Illuminate\Mail\Events\MessageSent;
use Illuminate\Notifications\Events\NotificationFailed;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Log;
use Symfony\Component\Mime\Address;

class MailDevelopmentLogger
{
    public static function register(): void
    {
        Event::listen(MessageSending::class, function (MessageSending $event) {
            self::info('SMTP: enviando mensaje', [
                'to' => self::formatAddresses($event->message->getTo()),
                'from' => self::formatAddresses($event->message->getFrom()),
                'subject' => $event->message->getSubject(),
            ]);
        });

        Event::listen(MessageSent::class, function (MessageSent $event) {
            self::info('SMTP: mensaje aceptado por el servidor', [
                'to' => self::formatAddresses($event->message->getTo()),
                'subject' => $event->message->getSubject(),
            ]);
        });

        Event::listen(NotificationFailed::class, function (NotificationFailed $event) {
            $exception = $event->data['exception'] ?? null;

            self::error('Notificación falló', [
                'channel' => $event->channel,
                'notification' => $event->notification::class,
                'notifiable_email' => $event->notifiable->email ?? null,
                'error' => $exception instanceof \Throwable ? $exception->getMessage() : null,
            ]);
        });
    }

    /**
     * @param  array<string, mixed>  $context
     */
    public static function info(string $message, array $context = []): void
    {
        if (! app()->environment('local')) {
            return;
        }

        Log::channel('mail')->info($message, $context);
    }

    /**
     * @param  array<string, mixed>  $context
     */
    public static function error(string $message, array $context = []): void
    {
        if (! app()->environment('local')) {
            return;
        }

        Log::channel('mail')->error($message, $context);
    }

    /**
     * @param  Address[]|null  $addresses
     * @return list<string>
     */
    protected static function formatAddresses(?array $addresses): array
    {
        if ($addresses === null) {
            return [];
        }

        return array_values(array_map(
            fn (Address $address) => $address->getAddress(),
            $addresses,
        ));
    }
}
