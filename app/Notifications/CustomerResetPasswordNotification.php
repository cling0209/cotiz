<?php

namespace App\Notifications;

use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Notifications\Messages\MailMessage;

class CustomerResetPasswordNotification extends ResetPassword
{
    public function toMail($notifiable): MailMessage
    {
        $url = url(route('account.password.reset', [
            'token' => $this->token,
            'email' => $notifiable->getEmailForPasswordReset(),
        ], false));

        $expire = (string) config('auth.passwords.users.expire', 60);

        return (new MailMessage)
            ->subject('Recuperar contraseña — '.config('app.name', 'Tienda Rómulo'))
            ->greeting('Hola, '.$notifiable->name)
            ->line('Recibiste este correo porque se solicitó restablecer la contraseña de tu cuenta.')
            ->action('Restablecer contraseña', $url)
            ->line('Este enlace expira en '.$expire.' minutos.')
            ->line('Si no solicitaste este cambio, puedes ignorar el mensaje.');
    }
}
