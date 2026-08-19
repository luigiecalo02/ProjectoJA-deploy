<?php

namespace App\Modules\Settings\Services;

use App\Models\User;
use App\Modules\Settings\Models\AppSetting;
use App\Modules\Shared\Models\StoredFile;
use App\Modules\Shared\Services\AuditLogger;
use App\Modules\Shared\Services\ImageOptimizer;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use InvalidArgumentException;
use Symfony\Component\HttpFoundation\StreamedResponse;

final class BrandSettingsService
{
    public function __construct(
        private readonly AuditLogger $auditLogger,
        private readonly ImageOptimizer $imageOptimizer,
    ) {}

    public function current(): AppSetting
    {
        return AppSetting::current();
    }

    /**
     * @return array{
     *     login_hero_url: ?string,
     *     login_hero_fit: array{x: float, y: float, zoom: float},
     *     pattern_light_url: ?string,
     *     pattern_dark_url: ?string,
     *     loaders: array<string, array<string, mixed>>,
     *     updated_at: ?string
     * }
     */
    public function payload(?AppSetting $settings = null): array
    {
        $settings ??= $this->current();

        return [
            'login_hero_url' => $this->publicUrl($settings->login_hero_path),
            'login_hero_fit' => $this->heroFitFrom($settings),
            'login_hero_copy' => $this->heroCopyFrom($settings),
            'pattern_light_url' => $this->publicUrl($settings->pattern_light_path),
            'pattern_dark_url' => $this->publicUrl($settings->pattern_dark_path),
            'loaders' => $this->loaderPayload($settings),
            'updated_at' => $settings->updated_at?->toIso8601String(),
        ];
    }

    /**
     * @param  array{x?: mixed, y?: mixed, zoom?: mixed}  $fit
     */
    public function updateHeroFit(array $fit, User $actor): AppSetting
    {
        $settings = $this->current();
        $old = $this->heroFitFrom($settings);
        $next = AppSetting::normalizeHeroFit($fit);
        $presets = is_array($settings->loader_presets) ? $settings->loader_presets : [];
        $presets[AppSetting::HERO_FIT_KEY] = $next;

        $settings->update([
            'loader_presets' => $presets,
            'updated_by' => $actor->id,
        ]);

        $this->auditLogger->log('settings', 'brand.hero_fit', $old, $next, $settings);

        return $settings->fresh() ?? $settings;
    }

    /**
     * @param  array<string, mixed>  $copy
     */
    public function updateHeroCopy(array $copy, User $actor): AppSetting
    {
        $settings = $this->current();
        $old = $this->heroCopyFrom($settings);
        $next = AppSetting::normalizeHeroCopy($copy);
        $presets = is_array($settings->loader_presets) ? $settings->loader_presets : [];
        $presets[AppSetting::HERO_COPY_KEY] = $next;
        $presets[AppSetting::HERO_FIT_KEY] = $next['desktop']['fit'];

        $settings->update([
            'loader_presets' => $presets,
            'updated_by' => $actor->id,
        ]);

        $this->auditLogger->log('settings', 'brand.hero_copy', $old, $next, $settings);

        return $settings->fresh() ?? $settings;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function updateLoader(string $key, array $data, User $actor): AppSetting
    {
        $this->assertLoaderKey($key);
        $settings = $this->current();
        $presets = $this->mergedPresets($settings);
        $old = $presets[$key];

        $presets[$key] = [
            ...$old,
            'ring_top' => $data['ring_top'] ?? $old['ring_top'],
            'ring_right' => $data['ring_right'] ?? $old['ring_right'],
            'glow' => $data['glow'] ?? $old['glow'],
            'label_color' => $data['label_color'] ?? $old['label_color'],
            'logo_animation' => $data['logo_animation'] ?? $old['logo_animation'],
            'ring_animation' => $data['ring_animation'] ?? $old['ring_animation'],
            'speed' => $data['speed'] ?? $old['speed'],
        ];

        $settings->update([
            'loader_presets' => $this->withHeroFit($settings, null, $presets),
            'updated_by' => $actor->id,
        ]);

        $this->auditLogger->log('settings', 'loader.'.$key, $old, $presets[$key], $settings);

        return $settings->fresh() ?? $settings;
    }

    public function storeLoaderLogo(string $key, UploadedFile $file, User $actor): AppSetting
    {
        $this->assertLoaderKey($key);
        $settings = $this->current();
        $presets = $this->mergedPresets($settings);
        $oldPath = $presets[$key]['logo_path'] ?? null;
        $stored = $this->imageOptimizer->store($file, "brand/loaders/{$key}", 'loader');
        $storedPath = $stored->path;

        StoredFile::query()->create([
            'name' => $file->getClientOriginalName(),
            'path' => $stored->path,
            'size' => $stored->size,
            'mime_type' => $stored->mime,
            'hash' => $stored->hash,
            'uploaded_by' => $actor->id,
        ]);

        $presets[$key]['logo_path'] = $storedPath;
        $settings->loader_presets = $this->withHeroFit($settings, null, $presets);
        $settings->updated_by = $actor->id;
        $settings->save();

        $this->deleteStoredPath(is_string($oldPath) ? $oldPath : null);
        $this->auditLogger->log('settings', 'loader.logo.'.$key, ['logo_path' => $oldPath], ['logo_path' => $storedPath], $settings);

        return $settings->fresh() ?? $settings;
    }

    public function resetLoaderLogo(string $key, User $actor): AppSetting
    {
        $this->assertLoaderKey($key);
        $settings = $this->current();
        $presets = $this->mergedPresets($settings);
        $oldPath = $presets[$key]['logo_path'] ?? null;
        $presets[$key]['logo_path'] = null;

        $settings->update([
            'loader_presets' => $this->withHeroFit($settings, null, $presets),
            'updated_by' => $actor->id,
        ]);

        $this->deleteStoredPath(is_string($oldPath) ? $oldPath : null);
        $this->auditLogger->log('settings', 'loader.logo.reset.'.$key, ['logo_path' => $oldPath], ['logo_path' => null], $settings);

        return $settings->fresh() ?? $settings;
    }

    public function resetLoader(string $key, User $actor): AppSetting
    {
        $this->assertLoaderKey($key);
        $settings = $this->current();
        $presets = $this->mergedPresets($settings);
        $old = $presets[$key];
        $this->deleteStoredPath(is_string($old['logo_path'] ?? null) ? $old['logo_path'] : null);
        $presets[$key] = AppSetting::defaultLoaderPresets()[$key];

        $settings->update([
            'loader_presets' => $this->withHeroFit($settings, null, $presets),
            'updated_by' => $actor->id,
        ]);

        $this->auditLogger->log('settings', 'loader.reset.'.$key, $old, $presets[$key], $settings);

        return $settings->fresh() ?? $settings;
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private function loaderPayload(AppSetting $settings): array
    {
        $payload = [];
        foreach ($this->mergedPresets($settings) as $key => $preset) {
            $payload[$key] = [
                'key' => $key,
                'logo_url' => $this->publicUrl(is_string($preset['logo_path'] ?? null) ? $preset['logo_path'] : null),
                'ring_top' => $preset['ring_top'],
                'ring_right' => $preset['ring_right'],
                'glow' => $preset['glow'],
                'label_color' => $preset['label_color'],
                'logo_animation' => $preset['logo_animation'],
                'ring_animation' => $preset['ring_animation'],
                'speed' => $preset['speed'],
            ];
        }

        return $payload;
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private function mergedPresets(AppSetting $settings): array
    {
        $stored = is_array($settings->loader_presets) ? $settings->loader_presets : [];
        $merged = [];

        foreach (AppSetting::defaultLoaderPresets() as $key => $defaults) {
            $override = is_array($stored[$key] ?? null) ? $stored[$key] : [];
            $merged[$key] = [...$defaults, ...$override];
        }

        return $merged;
    }

    /**
     * @return array{x: float, y: float, zoom: float}
     */
    private function heroFitFrom(AppSetting $settings): array
    {
        $stored = is_array($settings->loader_presets) ? $settings->loader_presets : [];
        $fit = is_array($stored[AppSetting::HERO_FIT_KEY] ?? null) ? $stored[AppSetting::HERO_FIT_KEY] : null;

        return AppSetting::normalizeHeroFit($fit);
    }

    /**
     * @param  array{x: float, y: float, zoom: float}|null  $fit
     * @param  array<string, mixed>|null  $presets
     * @return array<string, mixed>
     */
    private function withHeroFit(AppSetting $settings, ?array $fit = null, ?array $presets = null): array
    {
        $presets ??= $this->mergedPresets($settings);
        $presets[AppSetting::HERO_FIT_KEY] = AppSetting::normalizeHeroFit($fit ?? $this->heroFitFrom($settings));
        $presets[AppSetting::HERO_COPY_KEY] = $this->heroCopyFrom($settings);

        return $presets;
    }

    /**
     * @return array{desktop: array<string, mixed>, mobile: array<string, mixed>}
     */
    private function heroCopyFrom(AppSetting $settings): array
    {
        $stored = is_array($settings->loader_presets) ? $settings->loader_presets : [];
        $copy = is_array($stored[AppSetting::HERO_COPY_KEY] ?? null) ? $stored[AppSetting::HERO_COPY_KEY] : [];
        $normalized = AppSetting::normalizeHeroCopy($copy);
        $legacyFit = $this->heroFitFrom($settings);
        if (! is_array($copy['desktop']['fit'] ?? null)) {
            $normalized['desktop']['fit'] = $legacyFit;
        }

        return $normalized;
    }

    private function assertLoaderKey(string $key): void
    {
        if (! AppSetting::isLoaderKey($key)) {
            throw new InvalidArgumentException('Tipo de cargador no válido.');
        }
    }

    public function storeAsset(string $asset, UploadedFile $file, User $actor): AppSetting
    {
        $column = $this->columnFor($asset);
        $settings = $this->current();
        $oldPath = $settings->{$column};
        $stored = $this->imageOptimizer->store($file, "brand/{$asset}", $asset);
        $storedPath = $stored->path;

        StoredFile::query()->create([
            'name' => $file->getClientOriginalName(),
            'path' => $stored->path,
            'size' => $stored->size,
            'mime_type' => $stored->mime,
            'hash' => $stored->hash,
            'uploaded_by' => $actor->id,
        ]);

        $payload = [
            $column => $storedPath,
            'updated_by' => $actor->id,
        ];
        if ($asset === AppSetting::ASSET_LOGIN_HERO) {
            $payload['loader_presets'] = $this->withHeroFit($settings, AppSetting::defaultHeroFit());
        }
        $settings->update($payload);

        $this->auditLogger->log(
            'settings',
            'brand.'.$asset,
            [$column => $oldPath],
            [$column => $storedPath],
            $settings,
        );

        return $settings->fresh() ?? $settings;
    }

    public function resetAsset(string $asset, User $actor): AppSetting
    {
        $column = $this->columnFor($asset);
        $settings = $this->current();
        $oldPath = $settings->{$column};

        $payload = [
            $column => null,
            'updated_by' => $actor->id,
        ];
        if ($asset === AppSetting::ASSET_LOGIN_HERO) {
            $payload['loader_presets'] = $this->withHeroFit($settings, AppSetting::defaultHeroFit());
        }
        $settings->update($payload);

        $this->deleteStoredPath($oldPath);

        $this->auditLogger->log(
            'settings',
            'brand.reset.'.$asset,
            [$column => $oldPath],
            [$column => null],
            $settings,
        );

        return $settings->fresh() ?? $settings;
    }

    private function columnFor(string $asset): string
    {
        if (! AppSetting::isAssetKey($asset)) {
            throw new InvalidArgumentException('Imagen de apariencia no válida.');
        }

        return AppSetting::ASSET_COLUMNS[$asset];
    }

    public function streamPublicBrandFile(string $path): StreamedResponse
    {
        $normalized = str_replace('\\', '/', ltrim($path, '/'));

        abort_unless(
            (bool) preg_match('/^brand\/[A-Za-z0-9_\/.-]+$/', $normalized)
            && ! str_contains($normalized, '..')
            && Storage::disk('public')->exists($normalized),
            404
        );

        return Storage::disk('public')->response($normalized);
    }

    private function publicUrl(?string $path): ?string
    {
        if (! $path) {
            return null;
        }

        return '/api/v1/settings/brand/file/'.$path;
    }

    private function deleteStoredPath(?string $path): void
    {
        if (! $path || ! str_starts_with($path, 'brand/')) {
            return;
        }

        Storage::disk('public')->delete($path);
    }
}
