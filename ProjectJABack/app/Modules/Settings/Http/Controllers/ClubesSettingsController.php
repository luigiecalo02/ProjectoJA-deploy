<?php

namespace App\Modules\Settings\Http\Controllers;

use App\Modules\Settings\Services\ClubesSettingsService;
use App\Modules\Shared\Http\Responses\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class ClubesSettingsController
{
    public function __construct(private readonly ClubesSettingsService $clubesSettings) {}

    public function publicShow(Request $request): JsonResponse
    {
        $data = $request->validate([
            'organizacion_id' => ['nullable', 'integer', 'min:1'],
        ]);

        return ApiResponse::success(
            $this->clubesSettings->publicBranding(
                isset($data['organizacion_id']) ? (int) $data['organizacion_id'] : null
            )
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
}
