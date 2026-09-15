<?php

namespace App\Modules\Auth\Services;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Notifications\Messages\MailMessage;

final class PasswordResetMailFactory
{
    public function __construct(private readonly BrandedMailView $brandedMail) {}

    public function mail(User $user, string $token): MailMessage
    {
        $front = $this->frontUrl();
        $orgId = $this->organizationId($user);
        $url = $front.'/restablecer-contrasena?token='.$token.'&email='.urlencode($user->email);
        if ($orgId) {
            $url .= '&organizacion_id='.$orgId;
        }
        $expire = (int) config('auth.passwords.users.expire', 60);
        $layout = $this->brandedMail->layout($orgId);
        $brand = $layout['brandName'] ?? 'ProjectJA';

        return (new MailMessage)
            ->subject($brand.' · Restablecer contraseña')
            ->view('emails.branded-panel', [
                ...$layout,
                'title' => 'Restablecer contraseña',
                'userName' => $user->name ?: 'amigo',
                'intro' => 'Recibes este correo electrónico porque hemos recibido una solicitud de restablecimiento de contraseña para tu cuenta.',
                'buttonLabel' => 'Restablecer contraseña',
                'url' => $url,
                'after' => 'Este enlace para restablecer la contraseña caducará en '.$expire.' minutos. Si no solicitó un restablecimiento de contraseña, no es necesario realizar ninguna otra acción.',
            ]);
    }

    private function frontUrl(): string
    {
        $request = request();
        if ($request instanceof Request && $request->header('X-Clubes-Client') === 'clubes') {
            $origin = $request->headers->get('Origin');
            if (is_string($origin) && $origin !== '') {
                return rtrim($origin, '/');
            }
        }

        return rtrim((string) config('app.frontend_url'), '/');
    }

    private function organizationId(User $user): ?int
    {
        $request = request();
        if (! $request instanceof Request || $request->header('X-Clubes-Client') !== 'clubes') {
            return null;
        }

        $tenant = app(ClubesTenantAccess::class);
        $fromUser = $tenant->organizationIdForUser($user);
        if ($fromUser) {
            return $fromUser;
        }

        $selected = (int) $request->input('organizacion_id');
        if ($selected > 0) {
            return $selected;
        }

        return $tenant->rootId();
    }
}
