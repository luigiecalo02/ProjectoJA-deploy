<?php

namespace App\Modules\Auth\Services;

use App\Modules\Settings\Models\AppSetting;
use App\Modules\Settings\Services\BrandSettingsService;
use App\Modules\Settings\Services\ClubesSettingsService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

final class BrandedMailView
{
    public function __construct(
        private readonly BrandSettingsService $brand,
        private readonly ClubesSettingsService $clubes,
    ) {}

    /**
     * @return array{
     *     line1: string,
     *     line2: string,
     *     subtitle: string,
     *     heroPath: ?string,
     *     patternPath: ?string,
     *     logoPath: ?string,
     *     brandName: string,
     *     footer: string
     * }
     */
    public function layout(?int $organizacionId = null): array
    {
        if ($organizacionId && $this->isClubesRequest()) {
            return $this->clubesLayout($organizacionId);
        }

        return $this->platformLayout();
    }

    /**
     * @return array{
     *     line1: string,
     *     line2: string,
     *     subtitle: string,
     *     heroPath: ?string,
     *     patternPath: ?string,
     *     logoPath: ?string,
     *     brandName: string,
     *     footer: string
     * }
     */
    private function platformLayout(): array
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
            'brandName' => 'ProjectJA',
            'footer' => 'ProjectJA · Clubes de Conquistadores, Aventureros y Guías Mayores',
        ];
    }

    /**
     * @return array{
     *     line1: string,
     *     line2: string,
     *     subtitle: string,
     *     heroPath: ?string,
     *     patternPath: ?string,
     *     logoPath: ?string,
     *     brandName: string,
     *     footer: string
     * }
     */
    private function clubesLayout(int $organizacionId): array
    {
        $branding = $this->clubes->mailBranding($organizacionId);
        $brandName = trim((string) ($branding['title'] ?: $branding['kicker'] ?: 'Clubes'));
        $motto = trim((string) $branding['motto']);

        return [
            'line1' => (string) $branding['kicker'],
            'line2' => (string) $branding['title'],
            'subtitle' => (string) $branding['subtitle'],
            'heroPath' => $this->storedPath($branding['banner_path']),
            'patternPath' => null,
            'logoPath' => $this->storedPath($branding['logo_path']),
            'brandName' => $brandName,
            'footer' => $motto !== '' ? $brandName.' · '.$motto : $brandName,
        ];
    }

    private function isClubesRequest(): bool
    {
        $request = request();

        return $request instanceof Request && $request->header('X-Clubes-Client') === 'clubes';
    }

    private function storedPath(?string $stored): ?string
    {
        if ($stored && Storage::disk('public')->exists($stored)) {
            return Storage::disk('public')->path($stored);
        }

        return null;
    }

    private function resolvePath(?string $stored, string $frontendFile): ?string
    {
        $fromStorage = $this->storedPath($stored);
        if ($fromStorage) {
            return $fromStorage;
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
