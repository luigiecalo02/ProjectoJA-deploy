<?php

namespace App\Modules\Settings\Models;

use App\Models\User;
use App\Modules\Organizations\Models\Organizacion;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Schema;

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

    public const CLUBES_ASSET_LOGO = 'logo';

    public const CLUBES_ASSET_BACKGROUND = 'background';

    public const CLUBES_ASSET_BANNER = 'banner';

    public const CLUBES_ASSET_KEYS = [
        self::CLUBES_ASSET_LOGO,
        self::CLUBES_ASSET_BACKGROUND,
        self::CLUBES_ASSET_BANNER,
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
        'organizacion_id',
        'login_hero_path',
        'login_logos_path',
        'pattern_light_path',
        'pattern_dark_path',
        'loader_presets',
        'mail',
        'public_form',
        'clubes',
        'updated_by',
    ];

    protected function casts(): array
    {
        return [
            'loader_presets' => 'array',
            'mail' => 'array',
            'public_form' => 'array',
            'clubes' => 'array',
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

    public function organizacion(): BelongsTo
    {
        return $this->belongsTo(Organizacion::class, 'organizacion_id');
    }

    public static function current(?int $organizacionId = null): self
    {
        if (! self::hasOrganizacionColumn()) {
            return static::query()->firstOrCreate(['id' => self::SINGLETON_ID]);
        }

        $organizacionId ??= self::resolveOrganizacionId();

        if ($organizacionId) {
            return static::forOrganizacion($organizacionId);
        }

        return static::platform();
    }

    public static function platform(): self
    {
        if (! self::hasOrganizacionColumn()) {
            return static::query()->firstOrCreate(['id' => self::SINGLETON_ID]);
        }

        $row = static::query()->whereNull('organizacion_id')->first();
        if ($row) {
            return $row;
        }

        $legacy = static::query()->find(self::SINGLETON_ID);
        if ($legacy && $legacy->organizacion_id === null) {
            return $legacy;
        }

        return static::query()->create([
            'organizacion_id' => null,
            'clubes' => self::defaultClubesConfig(),
        ]);
    }

    public static function forOrganizacion(int $organizacionId): self
    {
        $row = static::query()->where('organizacion_id', $organizacionId)->first();
        if ($row) {
            return $row;
        }

        $platform = static::platform();

        try {
            return static::query()->create([
                'organizacion_id' => $organizacionId,
                'login_hero_path' => $platform->login_hero_path,
                'login_logos_path' => $platform->login_logos_path,
                'pattern_light_path' => $platform->pattern_light_path,
                'pattern_dark_path' => $platform->pattern_dark_path,
                'loader_presets' => $platform->loader_presets,
                'public_form' => $platform->public_form,
                'clubes' => self::defaultClubesConfig(),
            ]);
        } catch (QueryException $exception) {
            $row = static::query()->where('organizacion_id', $organizacionId)->first();
            if ($row) {
                return $row;
            }

            throw $exception;
        }
    }

    public static function resolveOrganizacionId(): ?int
    {
        $user = auth()->user();
        if (! $user) {
            return null;
        }

        $id = $user->active_organizacion_id ?? null;

        return $id ? (int) $id : null;
    }

    /**
     * @return array<string, mixed>
     */
    public static function defaultClubesConfig(): array
    {
        return [
            'source' => 'clubes',
            'scene_theme' => 'night',
            'kicker' => 'Club de Conquistadores',
            'title' => 'CONQUISTADORES',
            'subtitle' => 'Conectados con la misión',
            'motto' => 'Una misión, un propósito',
            'values' => 'Disciplina · Servicio · Amor',
            'logo_path' => null,
            'background_path' => null,
            'banner_path' => null,
        ];
    }

    /**
     * @param  array<string, mixed>|null  $data
     * @return array<string, mixed>
     */
    public static function normalizeClubesConfig(?array $data): array
    {
        $defaults = self::defaultClubesConfig();

        return [
            'source' => 'clubes',
            'scene_theme' => ($data['scene_theme'] ?? '') === 'day' ? 'day' : 'night',
            'kicker' => mb_substr(trim((string) ($data['kicker'] ?? $defaults['kicker'])), 0, 80),
            'title' => mb_substr(trim((string) ($data['title'] ?? $defaults['title'])), 0, 80),
            'subtitle' => mb_substr(trim((string) ($data['subtitle'] ?? $defaults['subtitle'])), 0, 160),
            'motto' => mb_substr(trim((string) ($data['motto'] ?? $defaults['motto'])), 0, 120),
            'values' => mb_substr(trim((string) ($data['values'] ?? $defaults['values'])), 0, 160),
            'logo_path' => self::normalizeClubesAssetPath($data['logo_path'] ?? null),
            'background_path' => self::normalizeClubesAssetPath($data['background_path'] ?? null),
            'banner_path' => self::normalizeClubesAssetPath($data['banner_path'] ?? null),
        ];
    }

    public static function isClubesAssetKey(string $key): bool
    {
        return in_array($key, self::CLUBES_ASSET_KEYS, true);
    }

    public static function normalizeClubesAssetPath(mixed $path): ?string
    {
        if (! is_string($path) || trim($path) === '') {
            return null;
        }

        $normalized = str_replace('\\', '/', ltrim($path, '/'));
        if (! str_starts_with($normalized, 'brand/') || str_contains($normalized, '..')) {
            return null;
        }

        return $normalized;
    }

    private static function hasOrganizacionColumn(): bool
    {
        return Schema::hasColumn((new static)->getTable(), 'organizacion_id');
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
                'kicker' => 'Club de Conquistadores',
                'title' => 'CONQUISTADORES',
                'subtitle' => 'Conectados con la misión',
                'motto' => 'Una misión, un propósito',
                'values' => 'Disciplina · Servicio · Amor',
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
                'kicker' => 'Club de Aventureros',
                'title' => 'AVENTUREROS',
                'subtitle' => 'Creciendo con Jesús',
                'motto' => 'Porque te amo, te enseño el camino',
                'values' => 'Amor · Servicio · Gratitud',
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
                'kicker' => 'Guías Mayores',
                'title' => 'GUÍAS MAYORES',
                'subtitle' => 'Liderazgo y servicio',
                'motto' => 'El amor de Cristo nos constriñe',
                'values' => 'Servicio · Liderazgo · Misión',
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
                'kicker' => 'Clubes',
                'title' => 'CLUBES',
                'subtitle' => 'Conectados con la misión',
                'motto' => 'Una misión, un propósito',
                'values' => 'Disciplina · Servicio · Amor',
            ],
        ];
    }
}
