<?php

namespace App\Modules\Settings\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AppSetting extends Model
{
    public const SINGLETON_ID = 1;

    public const ASSET_LOGIN_HERO = 'login_hero';

    public const ASSET_LOGIN_LOGOS = 'login_logos';

    public const ASSET_PATTERN_LIGHT = 'pattern_light';

    public const ASSET_PATTERN_DARK = 'pattern_dark';

    public const ASSET_COLUMNS = [
        self::ASSET_LOGIN_HERO => 'login_hero_path',
        self::ASSET_LOGIN_LOGOS => 'login_logos_path',
        self::ASSET_PATTERN_LIGHT => 'pattern_light_path',
        self::ASSET_PATTERN_DARK => 'pattern_dark_path',
    ];

    public const LOADER_KEYS = [
        'neutral',
        'aventureros',
        'conquistadores',
        'guias_mayores',
    ];

    public const LOGO_ANIMATIONS = ['float', 'pulse', 'spin', 'bounce', 'none'];

    public const RING_ANIMATIONS = ['spin', 'pulse', 'none'];

    public const LOADER_SPEEDS = ['slow', 'normal', 'fast'];

    public const HERO_FIT_KEY = '_login_hero_fit';

    public const HERO_COPY_KEY = '_login_hero_copy';

    public const HERO_ICONS = [
        'pi pi-users',
        'pi pi-shield',
        'pi pi-heart',
        'pi pi-flag',
        'pi pi-star',
        'pi pi-book',
        'pi pi-globe',
        'pi pi-home',
        'pi pi-map',
        'pi pi-compass',
        'pi pi-building',
        'pi pi-calendar',
        'pi pi-check-circle',
        'pi pi-sun',
        'pi pi-bolt',
        'pi pi-comments',
        'pi pi-verified',
        'pi pi-trophy',
        'pi pi-sparkles',
        'pi pi-sitemap',
    ];

    protected $fillable = [
        'login_hero_path',
        'login_logos_path',
        'pattern_light_path',
        'pattern_dark_path',
        'loader_presets',
        'mail',
        'public_form',
        'updated_by',
    ];

    protected function casts(): array
    {
        return [
            'loader_presets' => 'array',
            'mail' => 'array',
            'public_form' => 'array',
        ];
    }

    /**
     * @return array{x: float, y: float, zoom: float}
     */
    public static function defaultHeroFit(): array
    {
        return [
            'x' => 50.0,
            'y' => 50.0,
            'zoom' => 1.0,
        ];
    }

    /**
     * @param  array<string, mixed>|null  $fit
     * @return array{x: float, y: float, zoom: float}
     */
    public static function normalizeHeroFit(?array $fit): array
    {
        $defaults = self::defaultHeroFit();

        return [
            'x' => max(0.0, min(100.0, (float) ($fit['x'] ?? $defaults['x']))),
            'y' => max(0.0, min(100.0, (float) ($fit['y'] ?? $defaults['y']))),
            'zoom' => max(1.0, min(2.5, (float) ($fit['zoom'] ?? $defaults['zoom']))),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function defaultHeroVariant(): array
    {
        return [
            'line1' => 'Unidos para',
            'line2' => 'Servir y Salvar',
            'subtitle' => 'Plataforma oficial para la gestión de clubes de Conquistadores, Aventureros y Jóvenes Adventistas.',
            'features' => [
                [
                    'icon' => 'pi pi-users',
                    'title' => 'Gestión de Clubes',
                    'desc' => 'Organiza y administra tus clubes de forma eficiente.',
                ],
                [
                    'icon' => 'pi pi-shield',
                    'title' => 'Crecimiento Espiritual',
                    'desc' => 'Herramientas para el desarrollo espiritual y personal.',
                ],
                [
                    'icon' => 'pi pi-heart',
                    'title' => 'Servicio y Amistad',
                    'desc' => 'Juntos para hacer la diferencia en nuestra comunidad.',
                ],
            ],
            'fit' => self::defaultHeroFit(),
        ];
    }

    /**
     * @return array{desktop: array<string, mixed>, mobile: array<string, mixed>}
     */
    public static function defaultHeroCopy(): array
    {
        $variant = self::defaultHeroVariant();

        return [
            'desktop' => $variant,
            'mobile' => $variant,
        ];
    }

    /**
     * @param  array<string, mixed>|null  $copy
     * @return array{desktop: array<string, mixed>, mobile: array<string, mixed>}
     */
    public static function normalizeHeroCopy(?array $copy): array
    {
        return [
            'desktop' => self::normalizeHeroVariant(is_array($copy['desktop'] ?? null) ? $copy['desktop'] : null),
            'mobile' => self::normalizeHeroVariant(is_array($copy['mobile'] ?? null) ? $copy['mobile'] : null),
        ];
    }

    /**
     * @param  array<string, mixed>|null  $variant
     * @return array<string, mixed>
     */
    public static function normalizeHeroVariant(?array $variant): array
    {
        $defaults = self::defaultHeroVariant();
        $features = is_array($variant['features'] ?? null) ? $variant['features'] : [];

        return [
            'line1' => mb_substr(trim((string) ($variant['line1'] ?? $defaults['line1'])), 0, 80),
            'line2' => mb_substr(trim((string) ($variant['line2'] ?? $defaults['line2'])), 0, 80),
            'subtitle' => mb_substr(trim((string) ($variant['subtitle'] ?? $defaults['subtitle'])), 0, 240),
            'features' => [
                self::normalizeHeroFeature($features[0] ?? null, 0),
                self::normalizeHeroFeature($features[1] ?? null, 1),
                self::normalizeHeroFeature($features[2] ?? null, 2),
            ],
            'fit' => self::normalizeHeroFit(is_array($variant['fit'] ?? null) ? $variant['fit'] : null),
        ];
    }

    /**
     * @param  array<string, mixed>|null  $feature
     * @return array{icon: string, title: string, desc: string}
     */
    public static function normalizeHeroFeature(?array $feature, int $index): array
    {
        $defaults = self::defaultHeroVariant()['features'][$index] ?? self::defaultHeroVariant()['features'][0];
        $icon = (string) ($feature['icon'] ?? $defaults['icon']);
        if (! in_array($icon, self::HERO_ICONS, true)) {
            $icon = $defaults['icon'];
        }

        return [
            'icon' => $icon,
            'title' => mb_substr(trim((string) ($feature['title'] ?? $defaults['title'])), 0, 60),
            'desc' => mb_substr(trim((string) ($feature['desc'] ?? $defaults['desc'])), 0, 160),
        ];
    }

    public function editor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }

    public static function current(): self
    {
        return static::query()->firstOrCreate(['id' => self::SINGLETON_ID]);
    }

    public static function isAssetKey(string $key): bool
    {
        return array_key_exists($key, self::ASSET_COLUMNS);
    }

    public static function isLoaderKey(string $key): bool
    {
        return in_array($key, self::LOADER_KEYS, true);
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    public static function defaultLoaderPresets(): array
    {
        return [
            'conquistadores' => [
                'logo_path' => null,
                'ring_top' => '#ffcc00',
                'ring_right' => '#ed1c24',
                'glow' => '#0b2f6b',
                'label_color' => '#0b2f6b',
                'logo_animation' => 'float',
                'ring_animation' => 'spin',
                'speed' => 'normal',
            ],
            'aventureros' => [
                'logo_path' => null,
                'ring_top' => '#00aeef',
                'ring_right' => '#0b2f6b',
                'glow' => '#00aeef',
                'label_color' => '#0b2f6b',
                'logo_animation' => 'float',
                'ring_animation' => 'spin',
                'speed' => 'normal',
            ],
            'guias_mayores' => [
                'logo_path' => null,
                'ring_top' => '#f5c518',
                'ring_right' => '#0b2f6b',
                'glow' => '#0b2f6b',
                'label_color' => '#0b2f6b',
                'logo_animation' => 'float',
                'ring_animation' => 'spin',
                'speed' => 'normal',
            ],
            'neutral' => [
                'logo_path' => null,
                'ring_top' => '#0b2f6b',
                'ring_right' => '#f5c518',
                'glow' => '#0b2f6b',
                'label_color' => '#0b2f6b',
                'logo_animation' => 'float',
                'ring_animation' => 'spin',
                'speed' => 'normal',
            ],
        ];
    }
}
