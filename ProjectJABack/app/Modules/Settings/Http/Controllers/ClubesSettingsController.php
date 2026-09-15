<?php

namespace App\Modules\Settings\Http\Controllers;

use App\Modules\Auth\Services\ClubesTenantAccess;
use App\Modules\Events\Models\Event;
use App\Modules\Settings\Services\ClubesSettingsService;
use App\Modules\Shared\Http\Responses\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class ClubesSettingsController
{
    public function __construct(
        private readonly ClubesSettingsService $clubesSettings,
        private readonly ClubesTenantAccess $tenant,
    ) {}

    public function publicShow(Request $request): JsonResponse
    {
        $data = $request->validate([
            'organizacion_id' => ['nullable', 'integer', 'min:1'],
        ]);

        $orgId = isset($data['organizacion_id'])
            ? (int) $data['organizacion_id']
            : $this->tenant->rootId($request);

        if ($orgId) {
            $this->tenant->assertInTenant($orgId, $request);
        }

        return ApiResponse::success(
            $this->clubesSettings->publicBranding($orgId)
        )->header('Cache-Control', 'no-store, no-cache, must-revalidate');
    }

    public function show(Request $request): JsonResponse
    {
        return ApiResponse::success($this->clubesSettings->showFor($request->user()));
    }

    public function update(Request $request): JsonResponse
    {
        $data = $request->validate([
            'scene_theme' => ['required', 'string', 'in:night,day'],
            'kicker' => ['required', 'string', 'max:80'],
            'title' => ['required', 'string', 'max:80'],
            'subtitle' => ['required', 'string', 'max:160'],
            'motto' => ['required', 'string', 'max:120'],
            'values' => ['required', 'string', 'max:160'],
            'color_principal' => ['nullable', 'string', 'max:20', 'regex:/^#(?:[0-9a-fA-F]{3}|[0-9a-fA-F]{6})$/'],
            'color_secundario' => ['nullable', 'string', 'max:20', 'regex:/^#(?:[0-9a-fA-F]{3}|[0-9a-fA-F]{6})$/'],
        ]);

        return ApiResponse::success(
            $this->clubesSettings->update($data, $request->user()),
            'Configuración del club actualizada',
        );
    }

    public function uploadAsset(Request $request, string $asset): JsonResponse
    {
        $maxKilobytes = $asset === 'logo' ? 5120 : 8192;
        $request->validate([
            'image' => ['required', 'image', 'mimes:jpg,jpeg,png,webp', 'max:'.$maxKilobytes],
        ]);

        return ApiResponse::success(
            $this->clubesSettings->storeAsset($asset, $request->file('image'), $request->user()),
            'Imagen actualizada',
        );
    }

    public function resetAsset(Request $request, string $asset): JsonResponse
    {
        return ApiResponse::success(
            $this->clubesSettings->resetAsset($asset, $request->user()),
            'Imagen restaurada',
        );
    }

    public function storeEvent(Request $request): JsonResponse
    {
        abort_unless(
            $request->header('X-Clubes-Client') === 'clubes',
            403,
            'Este evento solo se puede crear desde el front de Clubes.',
        );

        $data = $this->validateEvent($request);

        return ApiResponse::success(
            $this->clubesSettings->createEvent(
                $request->user(),
                $data,
                $request->file('logo'),
                $request->file('banner'),
            ),
            'Evento creado',
            201,
        );
    }

    public function updateEvent(Request $request, Event $event): JsonResponse
    {
        abort_unless(
            $request->header('X-Clubes-Client') === 'clubes',
            403,
            'Este evento solo se puede actualizar desde el front de Clubes.',
        );

        $data = $this->validateEvent($request);

        return ApiResponse::success(
            $this->clubesSettings->updateEvent(
                $request->user(),
                $event,
                $data,
                $request->file('logo'),
                $request->file('banner'),
            ),
            'Evento actualizado',
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function validateEvent(Request $request): array
    {
        return $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'descripcion' => ['nullable', 'string'],
            'lugar' => ['nullable', 'string', 'max:255'],
            'starts_at' => ['required', 'date'],
            'ends_at' => ['required', 'date', 'after_or_equal:starts_at'],
            'tipo_evento_id' => ['nullable', 'integer', 'exists:tipo_evento,id'],
            'logo' => ['nullable', 'image', 'mimes:jpg,jpeg,png,webp', 'max:5120'],
            'banner' => ['nullable', 'image', 'mimes:jpg,jpeg,png,webp', 'max:8192'],
            'remove_logo' => ['sometimes', 'boolean'],
            'remove_banner' => ['sometimes', 'boolean'],
        ]);
    }
}
