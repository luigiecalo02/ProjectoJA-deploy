<?php

namespace App\Modules\Auth\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

final class ParticipantRegistrationOtpNotification extends Notification
{
    use Queueable;

    public function __construct(private readonly string $otp) {}

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject('Código de registro en ProjectJA')
            ->greeting('Código de verificación')
            ->line('Usa este código para continuar tu registro:')
            ->line($this->otp)
            ->line('El código vence en 10 minutos y solo puede utilizarse una vez.')
            ->line('Si no solicitaste este registro, ignora este mensaje.');
    }
}
