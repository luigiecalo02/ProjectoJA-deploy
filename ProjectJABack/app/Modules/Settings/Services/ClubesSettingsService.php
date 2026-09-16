<?php

namespace App\Modules\Settings\Services;

use App\Models\User;
use App\Modules\Clubs\Models\Club;
use App\Modules\Events\Models\Event;
use App\Modules\Events\Services\EventService;
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
        private readonly EventService $eventService,
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
     * Logo y banner de Clubes para el correo, subiendo por padres si esa org no los tiene.
     *
     * @return array{
     *     kicker: string,
     *     title: string,
     *     subtitle: string,
     *     motto: string,
     *     logo_path: ?string,
     *     banner_path: ?string
     * }
     */
    public function mailBranding(int $organizacionId): array
    {
        $current = Organizacion::query()->find($organizacionId);
        $copy = null;
        $logoPath = null;
        $bannerPath = null;

        while ($current) {
            $row = AppSetting::query()->where('organizacion_id', $current->id)->first();
            $clubes = is_array($row?->clubes) && $row->clubes !== []
                ? AppSetting::normalizeClubesConfig($row->clubes)
                : null;

            if ($clubes) {
                $copy ??= $clubes;
                $logoPath ??= is_string($clubes['logo_path'] ?? null) ? $clubes['logo_path'] : null;
                $bannerPath ??= is_string($clubes['banner_path'] ?? null) ? $clubes['banner_path'] : null;
            }

            if ($copy && $logoPath && $bannerPath) {
                break;
            }

            $current = $current->padre;
        }

        $copy ??= AppSetting::defaultClubesConfig();

        return [
            'kicker' => (string) $copy['kicker'],
            'title' => (string) $copy['title'],
            'subtitle' => (string) $copy['subtitle'],
            'motto' => (string) $copy['motto'],
            'logo_path' => $logoPath,
            'banner_path' => $bannerPath,
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
            ...$current,
            ...$data,
            'logo_path' => $current['logo_path'] ?? null,
            'background_path' => $current['background_path'] ?? null,
            'banner_path' => $current['banner_path'] ?? null,
            'background_night_path' => $current['background_night_path'] ?? null,
            'background_day_path' => $current['background_day_path'] ?? null,
            'color_principal' => $data['color_principal'] ?? $current['color_principal'] ?? null,
            'color_secundario' => $data['color_secundario'] ?? $current['color_secundario'] ?? null,
            'background_style' => $data['background_style'] ?? $current['background_style'] ?? 'cover',
            'background_night_style' => $data['background_night_style'] ?? $current['background_night_style'] ?? 'cover',
            'background_day_style' => $data['background_day_style'] ?? $current['background_day_style'] ?? 'cover',
        ]);
        $settings->updated_by = $actor->id;
        $settings->save();
        $this->syncClubColors($settings);

        return $this->payload($settings, false);
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public function createEvent(
        User $actor,
        array $data,
        ?UploadedFile $logo = null,
        ?UploadedFile $banner = null,
    ): array {
        $organizacionId = $this->assertCanWriteClubEvent($actor);
        $parentId = isset($data['evento_padre_id']) ? (int) $data['evento_padre_id'] : null;

        if ($parentId) {
            $parent = Event::query()->findOrFail($parentId);
            abort_unless(
                (int) $parent->organizacion_id === $organizacionId,
                Response::HTTP_FORBIDDEN,
                'El evento padre no pertenece a tu club.',
            );
            if (! $parent->tiene_subeventos) {
                $this->eventService->update($parent, $actor, ['tiene_subeventos' => true]);
            }
        }

        $event = $this->eventService->create($actor, [
            'name' => $data['name'],
            'descripcion' => $data['descripcion'] ?? null,
            'lugar' => $data['lugar'] ?? null,
            'starts_at' => $data['starts_at'],
            'ends_at' => $data['ends_at'],
            'tipo_evento_id' => $data['tipo_evento_id'] ?? null,
            'evento_padre_id' => $parentId,
            'organizacion_id' => $organizacionId,
            'organizacion_ids' => [$organizacionId],
            'estado' => Event::ESTADO_PUBLICADO,
            'visibilidad' => Event::VISIBILIDAD_ORGANIZACION,
            'permite_inscripcion_club' => true,
            'permite_inscripcion_organizacion' => true,
            'permite_inscribir_no_participantes' => true,
            'es_calificable' => false,
        ]);

        if ($logo) {
            $event = $this->eventService->storeImage($event, $logo, $actor);
        }
        if ($banner) {
            $event = $this->eventService->storeBanner($event, $banner, $actor);
        }

        return $this->eventPayload($event);
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public function updateEvent(
        User $actor,
        Event $event,
        array $data,
        ?UploadedFile $logo = null,
        ?UploadedFile $banner = null,
    ): array {
        $organizacionId = $this->assertCanWriteClubEvent($actor);
        abort_unless(
            (int) $event->organizacion_id === $organizacionId,
            Response::HTTP_FORBIDDEN,
            'Este evento no pertenece a tu club.',
        );

        $payload = [
            'name' => $data['name'],
            'descripcion' => $data['descripcion'] ?? null,
            'lugar' => $data['lugar'] ?? null,
            'starts_at' => $data['starts_at'],
            'ends_at' => $data['ends_at'],
            'tipo_evento_id' => $data['tipo_evento_id'] ?? null,
        ];
        if (! $logo && ! empty($data['remove_logo'])) {
            $payload['image_url'] = null;
        }
        if (! $banner && ! empty($data['remove_banner'])) {
            $payload['banner_url'] = null;
        }

        $event = $this->eventService->update($event, $actor, $payload);

        if ($logo) {
            $event = $this->eventService->storeImage($event, $logo, $actor);
        }
        if ($banner) {
            $event = $this->eventService->storeBanner($event, $banner, $actor);
        }

        return $this->eventPayload($event, (int) ($event->inscritos_count ?? 0));
    }

    private function assertCanWriteClubEvent(User $actor): int
    {
        abort_unless(
            $this->actorCanWriteClubEvent($actor),
            Response::HTTP_FORBIDDEN,
            'No tienes permiso para gestionar eventos desde este front.',
        );

        $organizacionId = AppSetting::resolveOrganizacionId();
        abort_unless($organizacionId, Response::HTTP_FORBIDDEN, 'Debes tener una organización activa.');

        return (int) $organizacionId;
    }

    /**
     * @return array<string, mixed>
     */
    private function eventPayload(Event $event, int $inscritosCount = 0): array
    {
        $event->loadMissing(['organizacion:id,nombre,codigo', 'tipoEvento:id,nombre,slug,color,icono']);

        return [
            'id' => $event->id,
            'evento_padre_id' => $event->evento_padre_id,
            'tiene_subeventos' => (bool) $event->tiene_subeventos,
            'hijos_count' => (int) ($event->hijos_count ?? $event->hijos()->count()),
            'name' => $event->name,
            'descripcion' => $event->descripcion,
            'lugar' => $event->lugar,
            'starts_at' => $event->starts_at?->toIso8601String(),
            'ends_at' => $event->ends_at?->toIso8601String(),
            'estado' => $event->estado,
            'visibilidad' => $event->visibilidad,
            'image_url' => $event->image_url,
            'banner_url' => $event->banner_url,
            'es_calificable' => (bool) $event->es_calificable,
            'permite_inscripcion_club' => (bool) $event->permite_inscripcion_club,
            'permite_inscripcion_organizacion' => (bool) $event->permite_inscripcion_organizacion,
            'permite_inscribir_no_participantes' => (bool) $event->permite_inscribir_no_participantes,
            'organizacion' => $event->organizacion
                ? [
                    'id' => $event->organizacion->id,
                    'nombre' => $event->organizacion->nombre,
                    'codigo' => $event->organizacion->codigo,
                ]
                : null,
            'tipo_evento' => $event->tipoEvento
                ? [
                    'id' => $event->tipoEvento->id,
                    'nombre' => $event->tipoEvento->nombre,
                    'slug' => $event->tipoEvento->slug,
                    'color' => $event->tipoEvento->color,
                    'icono' => $event->tipoEvento->icono,
                ]
                : null,
            'inscritos_count' => $inscritosCount,
        ];
    }

    private function actorIsDirector(?User $actor): bool
    {
        if (! $actor || ! AppSetting::resolveOrganizacionId()) {
            return false;
        }

        return in_array('director', $actor->roleNames(), true);
    }

    private function actorCanWriteClubEvent(?User $actor): bool
    {
        if (! $actor || ! AppSetting::resolveOrganizacionId()) {
            return false;
        }

        if ($actor->hasPermission(Event::PERMISSION_CREATE)
            || $actor->hasPermission(Event::PERMISSION_CREATE_ORGANIZATION)
            || $actor->hasPermission('events.update')) {
            return true;
        }

        return count(array_intersect($actor->roleNames(), [
            'director',
            'subdirector',
            'secretario',
        ])) > 0;
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

    private function syncClubColors(AppSetting $settings): void
    {
        if (! $settings->organizacion_id) {
            return;
        }

        $clubes = is_array($settings->clubes) ? $settings->clubes : [];
        Club::query()->where('organizacion_id', $settings->organizacion_id)->update([
            'color_principal' => $clubes['color_principal'] ?? null,
            'color_secundario' => $clubes['color_secundario'] ?? null,
        ]);
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
            'color_principal' => $normalized['color_principal'],
            'color_secundario' => $normalized['color_secundario'],
            'background_style' => $normalized['background_style'],
            'background_night_style' => $normalized['background_night_style'],
            'background_day_style' => $normalized['background_day_style'],
            'logo_url' => $this->fileUrl(is_string($normalized['logo_path'] ?? null) ? $normalized['logo_path'] : null),
            'background_url' => $this->fileUrl(is_string($normalized['background_path'] ?? null) ? $normalized['background_path'] : null),
            'banner_url' => $this->fileUrl(is_string($normalized['banner_path'] ?? null) ? $normalized['banner_path'] : null),
            'background_night_url' => $this->fileUrl(is_string($normalized['background_night_path'] ?? null) ? $normalized['background_night_path'] : null),
            'background_day_url' => $this->fileUrl(is_string($normalized['background_day_path'] ?? null) ? $normalized['background_day_path'] : null),
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
