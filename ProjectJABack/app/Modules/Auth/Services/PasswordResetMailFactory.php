<?php

namespace App\Modules\Auth\Services;

use App\Models\User;
use Illuminate\Notifications\Messages\MailMessage;

final class PasswordResetMailFactory
{
    public function __construct(private readonly BrandedMailView $brandedMail) {}

    public function mail(User $user, string $token): MailMessage
    {
        $front = rtrim((string) config('app.frontend_url'), '/');
        $url = $front.'/restablecer-contrasena?token='.$token.'&email='.urlencode($user->email);
        $expire = (int) config('auth.passwords.users.expire', 60);

        return (new MailMessage)
            ->subject('ProjectJA · Restablecer contraseña')
            ->view('emails.branded-panel', [
                ...$this->brandedMail->layout(),
                'title' => 'Restablecer contraseña',
                'userName' => $user->name ?: 'amigo',
                'intro' => 'Recibes este correo electrónico porque hemos recibido una solicitud de restablecimiento de contraseña para tu cuenta.',
                'buttonLabel' => 'Restablecer contraseña',
                'url' => $url,
                'after' => 'Este enlace para restablecer la contraseña caducará en '.$expire.' minutos. Si no solicitó un restablecimiento de contraseña, no es necesario realizar ninguna otra acción.',
            ]);
    }
}
