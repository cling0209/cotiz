<?php

namespace App\Notifications;

use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class AdminEmailCodeNotification extends Notification
{
    public function __construct(
        public string $code,
        public string $subjectLine,
        public string $introLine,
        public int $expiresMinutes = 15,
    ) {}

    public function via($notifiable): array
    {
        return ['mail'];
    }

    public function toMail($notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject($this->subjectLine)
            ->greeting('Hola, '.$notifiable->name)
            ->line($this->introLine)
            ->line('Tu código de verificación es:')
            ->line('**'.$this->code.'**')
            ->line('Este código expira en '.$this->expiresMinutes.' minutos.')
            ->line('Si no solicitaste este código, ignora este correo.');
    }
}
