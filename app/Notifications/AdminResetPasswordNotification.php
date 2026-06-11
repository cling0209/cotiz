<?php

namespace App\Notifications;

use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Notifications\Messages\MailMessage;

class AdminResetPasswordNotification extends ResetPassword
{
    public function toMail($notifiable): MailMessage
    {
        $url = url(route('admin.password.reset.link', [
            'token' => $this->token,
            'email' => $notifiable->getEmailForPasswordReset(),
        ], false));

        $expire = (string) config('auth.passwords.users.expire', 60);

        return (new MailMessage)
            ->subject('Recuperar contraseña del panel — '.config('app.name', 'Tienda Rómulo'))
            ->greeting('Hola, '.$notifiable->name)
            ->line('Recibiste este correo porque se solicitó restablecer la contraseña de tu cuenta de administrador.')
            ->action('Restablecer contraseña', $url)
            ->line('Este enlace expira en '.$expire.' minutos.')
            ->line('Si no solicitaste este cambio, puedes ignorar el mensaje.');
    }
}
