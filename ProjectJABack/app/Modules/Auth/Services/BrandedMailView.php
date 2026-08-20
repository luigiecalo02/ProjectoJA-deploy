<?php

namespace App\Modules\Auth\Services;

use App\Modules\Settings\Models\AppSetting;
use App\Modules\Settings\Services\BrandSettingsService;
use Illuminate\Support\Facades\Storage;

final class BrandedMailView
{
    public function __construct(private readonly BrandSettingsService $brand) {}

    /**
     * @return array{
     *     line1: string,
     *     line2: string,
     *     subtitle: string,
     *     heroPath: ?string,
     *     patternPath: ?string,
     *     logoPath: ?string
     * }
     */
    public function layout(): array
    {
        $settings = $this->brand->current();
        $copy = $this->brand->payload($settings)['login_hero_copy']['desktop'] ?? AppSetting::defaultHeroVariant();

        return [
            'line1' => (string) ($copy['line1'] ?? 'Unidos para'),
            'line2' => (string) ($copy['line2'] ?? 'Servir y Salvar'),
            'subtitle' => (string) ($copy['subtitle'] ?? 'Plataforma oficial para la gestión de clubes.'),
            'heroPath' => $this->resolvePath($settings->login_hero_path, 'login-hero.jpg'),
            'patternPath' => $this->resolvePath($settings->pattern_light_path, 'pattern-scout.png'),
            'logoPath' => $this->resolvePath($settings->login_logos_path, 'clubes-logos.png'),
        ];
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

        if (is_file($fallback)) {
            return $fallback;
        }

        $public = public_path('email/'.$frontendFile);

        return is_file($public) ? $public : null;
    }
}
