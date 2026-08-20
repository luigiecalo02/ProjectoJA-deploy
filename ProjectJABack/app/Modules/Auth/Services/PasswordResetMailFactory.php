<?php

namespace App\Modules\Auth\Services;

use App\Models\User;
use App\Modules\Settings\Models\AppSetting;
use App\Modules\Settings\Services\BrandSettingsService;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Support\Facades\Storage;

final class PasswordResetMailFactory
{
    public function __construct(private readonly BrandSettingsService $brand) {}

    public function mail(User $user, string $token): MailMessage
    {
        $front = rtrim((string) config('app.frontend_url'), '/');
        $url = $front.'/restablecer-contrasena?token='.$token.'&email='.urlencode($user->email);
        $settings = $this->brand->current();
        $copy = $this->brand->payload($settings)['login_hero_copy']['desktop'] ?? AppSetting::defaultHeroVariant();
        $expire = (int) config('auth.passwords.users.expire', 60);

        return (new MailMessage)
            ->subject('ProjectJA · Restablecer contraseña')
            ->view('emails.reset-password', [
                'userName' => $user->name ?: 'amigo',
                'url' => $url,
                'expire' => $expire,
                'line1' => (string) ($copy['line1'] ?? 'Unidos para'),
                'line2' => (string) ($copy['line2'] ?? 'Servir y Salvar'),
                'subtitle' => (string) ($copy['subtitle'] ?? 'Plataforma oficial para la gestión de clubes.'),
                'heroPath' => $this->resolvePath($settings->login_hero_path, 'login-hero.jpg'),
                'patternPath' => $this->resolvePath($settings->pattern_light_path, 'pattern-scout.png'),
                'logoPath' => $this->resolvePath($settings->login_logos_path, 'clubes-logos.png'),
            ]);
    }

    private function resolvePath(?string $stored, string $frontendFile): ?string
    {
        if ($stored && Storage::disk('public')->exists($stored)) {
            return Storage::disk('public')->path($stored);
        }

        $fallback = dirname(base_path())
            .DIRECTORY_SEPARATOR.'projectJAFront'
            .DIRECTORY_SEPARATOR.'src'
            .DIRECTORY_SEPARATOR.'assets'
            .DIRECTORY_SEPARATOR.'brand'
            .DIRECTORY_SEPARATOR.$frontendFile;

        return is_file($fallback) ? $fallback : $this->publicFallback($frontendFile);
    }

    private function publicFallback(string $filename): ?string
    {
        $path = public_path('email/'.$filename);

        return is_file($path) ? $path : null;
    }
}
