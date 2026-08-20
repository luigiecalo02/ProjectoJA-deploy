<?php

namespace App\Modules\Auth\Services;

use App\Models\User;
use App\Modules\Clubs\Models\Persona;
use App\Modules\Settings\Services\MailSettingsService;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Password;
use Illuminate\Validation\ValidationException;

final class AccountMailService
{
    public function __construct(private readonly MailSettingsService $mailSettings) {}

    public function sendPasswordReset(string $email): void
    {
        $this->requestPasswordReset($email, null);
    }

    /**
     * @return array{email: string, email_masked: string, sent: bool}
     */
    public function requestPasswordReset(?string $email, ?string $identificacion): array
    {
        $user = $this->findUserForRecovery($email, $identificacion);
        if (! $user) {
            throw ValidationException::withMessages([
                'lookup' => ['No encontramos una cuenta con esos datos.'],
            ]);
        }

        $this->ensureConfigured();
        $this->mailSettings->apply();

        $status = Password::sendResetLink(['email' => $user->email]);
        if ($status !== Password::RESET_LINK_SENT) {
            throw ValidationException::withMessages([
                'email' => ['No se pudo enviar el correo de recuperación.'],
            ]);
        }

        return [
            'email' => $user->email,
            'email_masked' => $this->maskEmail($user->email),
            'sent' => true,
        ];
    }

    public function resetPassword(array $data): void
    {
        $this->mailSettings->apply();

        $status = Password::reset(
            $data,
            function (User $user, string $password) {
                $user->forceFill(['password' => $password])->save();
                $user->tokens()->delete();
            }
        );

        if ($status !== Password::PASSWORD_RESET) {
            throw ValidationException::withMessages([
                'email' => ['El enlace no es válido o ya expiró.'],
            ]);
        }
    }

    public function sendVerification(User $user): void
    {
        if ($user->email_verified_at) {
            return;
        }
        if (! $this->mailSettings->isConfigured()) {
            return;
        }

        $this->mailSettings->apply();
        $code = $this->issueVerificationCode($user);
        $url = $this->verificationUrl($user);
        Mail::raw(
            "Tu código de verificación de ProjectJA es: {$code}\n\n".
            "Escríbelo en la pantalla de confirmar cuenta. Vence en 15 minutos.\n\n".
            "También puedes abrir este enlace:\n{$url}",
            function ($message) use ($user) {
                $message->to($user->email)->subject('ProjectJA · Código de verificación');
            }
        );
    }

    public function trySendVerification(User $user): void
    {
        try {
            $this->sendVerification($user);
        } catch (\Throwable) {
            // El registro no debe fallar si SMTP no responde.
        }
    }

    public function trySendApprovedNotice(User $user): void
    {
        try {
            $this->sendApprovedNotice($user);
        } catch (\Throwable) {
        }
    }

    public function sendApprovedNotice(User $user): void
    {
        if (! $this->mailSettings->isConfigured()) {
            return;
        }

        $this->mailSettings->apply();
        $login = rtrim((string) config('app.frontend_url'), '/').'/login';
        if ($user->email_verified_at) {
            $body = "Tu solicitud fue aprobada. Ya puedes entrar en {$login}";
        } else {
            $code = $this->issueVerificationCode($user);
            $body = "Tu solicitud fue aprobada. Confirma tu correo con este código: {$code}\n\n".
                "O abre este enlace:\n".$this->verificationUrl($user);
        }

        Mail::raw($body, function ($message) use ($user) {
            $message->to($user->email)->subject('ProjectJA · Solicitud aprobada');
        });
    }

    public function verify(int $userId, string $hash): User
    {
        $user = User::query()->findOrFail($userId);
        if (! hash_equals(sha1($user->email), $hash)) {
            throw ValidationException::withMessages([
                'hash' => ['El enlace de confirmación no es válido.'],
            ]);
        }

        if (! $user->email_verified_at) {
            $user->forceFill([
                'email_verified_at' => now(),
                'email_verification_code_hash' => null,
                'email_verification_expires_at' => null,
            ])->save();
        }

        return $user;
    }

    public function verifyCode(string $email, string $code): User
    {
        $user = User::query()->where('email', $email)->first();
        $valid = $user
            && ! $user->email_verified_at
            && filled($user->email_verification_code_hash)
            && $user->email_verification_expires_at?->isFuture()
            && Hash::check($code, $user->email_verification_code_hash);

        if (! $valid) {
            throw ValidationException::withMessages([
                'code' => ['El código no es válido o ya expiró.'],
            ]);
        }

        $user->forceFill([
            'email_verified_at' => now(),
            'email_verification_code_hash' => null,
            'email_verification_expires_at' => null,
        ])->save();

        return $user;
    }

    public function resend(string $email): void
    {
        $user = User::query()->where('email', $email)->first();
        if (! $user || $user->email_verified_at) {
            return;
        }

        $this->trySendVerification($user);
    }

    /**
     * @return array{email: string, email_masked: string, already_verified: bool, sent: bool}
     */
    public function recover(?string $email, ?string $identificacion): array
    {
        $user = $this->findUserForRecovery($email, $identificacion);
        if (! $user) {
            throw ValidationException::withMessages([
                'lookup' => ['No encontramos una cuenta con esos datos.'],
            ]);
        }

        $payload = [
            'email' => $user->email,
            'email_masked' => $this->maskEmail($user->email),
            'already_verified' => (bool) $user->email_verified_at,
            'sent' => false,
        ];

        if ($user->email_verified_at) {
            return $payload;
        }

        $this->ensureConfigured();

        try {
            $this->sendVerification($user);
        } catch (ValidationException $exception) {
            throw $exception;
        } catch (\Throwable) {
            throw ValidationException::withMessages([
                'email' => ['No se pudo enviar el correo de verificación.'],
            ]);
        }

        $payload['sent'] = true;

        return $payload;
    }

    private function findUserForRecovery(?string $email, ?string $identificacion): ?User
    {
        if (filled($email)) {
            return User::query()->where('email', trim($email))->first();
        }

        if (! filled($identificacion)) {
            return null;
        }

        $persona = Persona::query()
            ->where('identificacion', trim($identificacion))
            ->whereHas('user')
            ->first();

        return $persona?->user;
    }

    private function maskEmail(string $email): string
    {
        $parts = explode('@', $email, 2);
        $local = $parts[0] ?? '';
        $domain = $parts[1] ?? '';
        $keep = min(2, max(1, strlen($local) - 1));
        $localMasked = substr($local, 0, $keep).str_repeat('*', max(1, strlen($local) - $keep));
        $dot = strrpos($domain, '.');
        if ($dot === false) {
            return $localMasked.'@'.str_repeat('*', max(1, strlen($domain)));
        }

        $name = substr($domain, 0, $dot);
        $tld = substr($domain, $dot);
        $nameKeep = min(1, strlen($name));

        return $localMasked.'@'.substr($name, 0, $nameKeep).str_repeat('*', max(1, strlen($name) - $nameKeep)).$tld;
    }

    private function issueVerificationCode(User $user): string
    {
        $code = (string) random_int(100000, 999999);
        $user->forceFill([
            'email_verification_code_hash' => Hash::make($code),
            'email_verification_expires_at' => now()->addMinutes(15),
        ])->save();

        return $code;
    }

    private function verificationUrl(User $user): string
    {
        $front = rtrim((string) config('app.frontend_url'), '/');

        return $front.'/confirmar-cuenta?id='.$user->id.'&hash='.sha1($user->email);
    }

    private function ensureConfigured(): void
    {
        if (! $this->mailSettings->isConfigured()) {
            throw ValidationException::withMessages([
                'email' => ['El envío de correo no está configurado. Avísale al administrador.'],
            ]);
        }
    }
}
