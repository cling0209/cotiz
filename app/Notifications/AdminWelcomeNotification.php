<?php

namespace App\Notifications;

use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class AdminWelcomeNotification extends Notification
{
    public function via($notifiable): array
    {
        return ['mail'];
    }

    public function toMail($notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject('Cuenta de administrador — '.config('app.name', 'Tienda Rómulo'))
            ->greeting('Hola, '.$notifiable->name)
            ->line('Se creó tu cuenta de administrador en '.config('app.name', 'Tienda Rómulo').'.')
            ->line('Al iniciar sesión en el panel se te enviará un código de verificación a este correo.')
            ->action('Ir al panel', route('admin.login'));
    }
}
