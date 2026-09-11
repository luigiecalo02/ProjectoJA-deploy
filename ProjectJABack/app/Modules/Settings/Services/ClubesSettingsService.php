<?php

namespace App\Modules\Settings\Services;

use App\Models\User;
use App\Modules\Organizations\Models\Organizacion;
use App\Modules\Settings\Models\AppSetting;
use App\Modules\Shared\Models\StoredFile;
use App\Modules\Shared\Services\AuditLogger;
use App\Modules\Shared\Services\ImageOptimizer;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\Response;

final class ClubesSettingsService
{
    public function __construct(
        private readonly BrandSettingsService $brandSettings,
        private readonly ImageOptimizer $imageOptimizer,
        private readonly AuditLogger $auditLogger,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function publicBranding(?int $organizacionId = null): array
    {
        $org = $organizacionId
            ? Organizacion::query()->with(['tipo', 'club'])->find($organizacionId)
            : null;

        $loaderKey = $this->loaderKeyFor($org);
        $preset = $this->loaderPresetFor($org);

        $settings = $org
            ? AppSetting::query()->where('organizacion_id', $org->id)->first()
            : null;

        $hasOwnClubes = $settings && is_array($settings->clubes) && $settings->clubes !== [];
        $clubes = $this->clubesPayload($hasOwnClubes ? $settings->clubes : $preset);
        $presetLogo = is_string($preset['logo_url'] ?? null) ? $preset['logo_url'] : null;

        return [
            'organizacion_id' => $org?->id,
            'organizacion_nombre' => $org?->nombre,
            'tipo_nombre' => $org?->tipo?->nombre,
            'loader_key' => $loaderKey,
            'logo_url' => $clubes['logo_url'] ?? $presetLogo,
            'background_url' => $clubes['background_url'] ?? null,
            'banner_url' => $clubes['banner_url'] ?? null,
            'clubes' => $clubes,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function loaderPresetFor(?Organizacion $org): array
    {
        $loaderKey = $this->loaderKeyFor($org);
        $brand = $this->brandSettings->payload(AppSetting::platform());
        $loaders = is_array($brand['loaders'] ?? null) ? $brand['loaders'] : [];
        $preset = $loaders[$loaderKey] ?? $loaders['conquistadores'] ?? [];

        return is_array($preset) ? $preset : [];
    }

    private function loaderKeyFor(?Organizacion $org): string
    {
        if (! $org) {
            return 'conquistadores';
        }

        $fromTipo = match ((int) $org->tipo_organizacion_id) {
            Organizacion::TIPO_AVENTUREROS => 'aventureros',
            Organizacion::TIPO_GUIAS_MAYORES => 'guias_mayores',
            Organizacion::TIPO_CONQUISTADORES => 'conquistadores',
            default => null,
        };
        if ($fromTipo) {
            return $fromTipo;
        }

        $clubTipos = is_array($org->club?->tipos) ? $org->club->tipos : [];
        foreach (['conquistadores', 'aventureros', 'guias_mayores'] as $key) {
            if (in_array($key, $clubTipos, true)) {
                return $key;
            }
        }

        $nombre = mb_strtolower(trim((string) ($org->tipo?->nombre ?? $org->nombre ?? '')));
        if (str_contains($nombre, 'aventurer')) {
            return 'aventureros';
        }
        if (str_contains($nombre, 'guía') || str_contains($nombre, 'guia')) {
            return 'guias_mayores';
        }

        return 'conquistadores';
    }

    /**
     * @return array<string, mixed>|null
     */
    public function existing(): ?array
    {
        $settings = $this->currentIfPresent();
        if (! $settings) {
            return null;
        }

        return $this->payload($settings, false);
    }

    /**
     * @return array<string, mixed>|null
     */
    public function showFor(?User $actor): ?array
    {
        if ($this->actorIsDirector($actor)) {
            return $this->ensure($actor);
        }

        return $this->existing();
    }

    /**
     * @return array<string, mixed>
     */
    public function ensure(?User $actor = null): array
    {
        $organizacionId = AppSetting::resolveOrganizacionId();
        $existed = $organizacionId
            ? AppSetting::query()->where('organizacion_id', $organizacionId)->exists()
            : AppSetting::query()->whereNull('organizacion_id')->exists();

        $settings = AppSetting::current();
        $initialized = false;

        if (! is_array($settings->clubes) || $settings->clubes === []) {
            $org = $organizacionId
                ? Organizacion::query()->with(['tipo', 'club'])->find($organizacionId)
                : null;
            $settings->clubes = AppSetting::normalizeClubesConfig($this->loaderPresetFor($org));
            if ($actor) {
                $settings->updated_by = $actor->id;
            }
            $settings->save();
            $initialized = true;
        }

        return $this->payload($settings, ! $existed || $initialized);
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public function update(array $data, User $actor): array
    {
        abort_unless(
            $this->actorIsDirector($actor) || $actor->hasPermission('settings.update'),
            Response::HTTP_FORBIDDEN,
            'Solo el director del club puede actualizar esta configuración.',
        );

        if ($this->actorIsDirector($actor)) {
            $this->ensure($actor);
        }

        $settings = $this->currentIfPresent();
        abort_unless($settings, Response::HTTP_NOT_FOUND, 'Esta organización no tiene configuración del club.');

        $current = is_array($settings->clubes) ? $settings->clubes : [];
        $settings->clubes = AppSetting::normalizeClubesConfig([
            ...$data,
            'logo_path' => $current['logo_path'] ?? null,
            'background_path' => $current['background_path'] ?? null,
            'banner_path' => $current['banner_path'] ?? null,
        ]);
        $settings->updated_by = $actor->id;
        $settings->save();

        return $this->payload($settings, false);
    }

    private function actorIsDirector(?User $actor): bool
    {
        if (! $actor || ! AppSetting::resolveOrganizacionId()) {
            return false;
        }

        return in_array('director', $actor->roleNames(), true);
    }

    private function currentIfPresent(): ?AppSetting
    {
        $organizacionId = AppSetting::resolveOrganizacionId();
        if (! $organizacionId) {
            return null;
        }

        $settings = AppSetting::query()->where('organizacion_id', $organizacionId)->first();
        if (! $settings || ! is_array($settings->clubes) || $settings->clubes === []) {
            return null;
        }

        return $settings;
    }

    /**
     * @return array<string, mixed>
     */
    public function payload(?AppSetting $settings = null, bool $initialized = false): array
    {
        $settings ??= AppSetting::current();
        $settings->loadMissing('organizacion');

        return [
            'id' => $settings->id,
            'organizacion_id' => $settings->organizacion_id,
            'organizacion_nombre' => $settings->organizacion?->nombre,
            'is_platform' => $settings->organizacion_id === null,
            'initialized' => $initialized,
            'clubes' => $this->clubesPayload(
                is_array($settings->clubes) ? $settings->clubes : null
            ),
            'updated_at' => $settings->updated_at?->toIso8601String(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function storeAsset(string $asset, UploadedFile $file, User $actor): array
    {
        $this->assertCanManage($actor);
        abort_unless(AppSetting::isClubesAssetKey($asset), Response::HTTP_NOT_FOUND);

        $this->ensure($actor);
        $settings = $this->currentIfPresent();
        abort_unless($settings, Response::HTTP_NOT_FOUND);

        $clubes = AppSetting::normalizeClubesConfig(
            is_array($settings->clubes) ? $settings->clubes : null
        );
        $pathKey = $asset.'_path';
        $oldPath = is_string($clubes[$pathKey] ?? null) ? $clubes[$pathKey] : null;
        $orgId = (int) $settings->organizacion_id;
        $stored = $this->imageOptimizer->store($file, "brand/clubes/{$orgId}", $asset);

        StoredFile::query()->create([
            'name' => $file->getClientOriginalName(),
            'path' => $stored->path,
            'size' => $stored->size,
            'mime_type' => $stored->mime,
            'hash' => $stored->hash,
            'uploaded_by' => $actor->id,
        ]);

        $clubes[$pathKey] = $stored->path;
        $settings->clubes = AppSetting::normalizeClubesConfig($clubes);
        $settings->updated_by = $actor->id;
        $settings->save();
        $this->deleteStoredPath($oldPath);
        $this->auditLogger->log(
            'settings',
            'clubes.'.$asset,
            [$pathKey => $oldPath],
            [$pathKey => $stored->path],
            $settings,
        );

        return $this->payload($settings, false);
    }

    /**
     * @return array<string, mixed>
     */
    public function resetAsset(string $asset, User $actor): array
    {
        $this->assertCanManage($actor);
        abort_unless(AppSetting::isClubesAssetKey($asset), Response::HTTP_NOT_FOUND);

        $settings = $this->currentIfPresent();
        abort_unless($settings, Response::HTTP_NOT_FOUND);

        $clubes = AppSetting::normalizeClubesConfig(
            is_array($settings->clubes) ? $settings->clubes : null
        );
        $pathKey = $asset.'_path';
        $oldPath = is_string($clubes[$pathKey] ?? null) ? $clubes[$pathKey] : null;
        $clubes[$pathKey] = null;
        $settings->clubes = AppSetting::normalizeClubesConfig($clubes);
        $settings->updated_by = $actor->id;
        $settings->save();
        $this->deleteStoredPath($oldPath);
        $this->auditLogger->log(
            'settings',
            'clubes.reset.'.$asset,
            [$pathKey => $oldPath],
            [$pathKey => null],
            $settings,
        );

        return $this->payload($settings, false);
    }

    private function assertCanManage(User $actor): void
    {
        abort_unless(
            $this->actorIsDirector($actor) || $actor->hasPermission('settings.update'),
            Response::HTTP_FORBIDDEN,
            'Solo el director del club puede actualizar esta configuración.',
        );
    }

    /**
     * @param  array<string, mixed>|null  $clubes
     * @return array<string, mixed>
     */
    private function clubesPayload(?array $clubes): array
    {
        $normalized = AppSetting::normalizeClubesConfig($clubes);

        return [
            'source' => $normalized['source'],
            'scene_theme' => $normalized['scene_theme'],
            'kicker' => $normalized['kicker'],
            'title' => $normalized['title'],
            'subtitle' => $normalized['subtitle'],
            'motto' => $normalized['motto'],
            'values' => $normalized['values'],
            'logo_url' => $this->fileUrl(is_string($normalized['logo_path'] ?? null) ? $normalized['logo_path'] : null),
            'background_url' => $this->fileUrl(is_string($normalized['background_path'] ?? null) ? $normalized['background_path'] : null),
            'banner_url' => $this->fileUrl(is_string($normalized['banner_path'] ?? null) ? $normalized['banner_path'] : null),
        ];
    }

    private function fileUrl(?string $path): ?string
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
