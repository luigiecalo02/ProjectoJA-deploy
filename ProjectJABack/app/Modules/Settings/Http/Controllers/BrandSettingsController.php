<?php

namespace App\Modules\Settings\Http\Controllers;

use App\Modules\Settings\Models\AppSetting;
use App\Modules\Settings\Services\BrandSettingsService;
use App\Modules\Shared\Http\Responses\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

final class BrandSettingsController
{
    public function __construct(private readonly BrandSettingsService $brandSettingsService) {}

    public function show(): JsonResponse
    {
        return ApiResponse::success($this->brandSettingsService->payload())
            ->header('Cache-Control', 'no-store, no-cache, must-revalidate');
    }

    public function file(string $path): StreamedResponse
    {
        return $this->brandSettingsService->streamPublicBrandFile($path);
    }

    public function updateHeroFit(Request $request): JsonResponse
    {
        abort_unless($request->user()?->hasPermission('settings.update'), Response::HTTP_FORBIDDEN);

        $data = $request->validate([
            'x' => ['required', 'numeric', 'between:0,100'],
            'y' => ['required', 'numeric', 'between:0,100'],
            'zoom' => ['required', 'numeric', 'between:1,2.5'],
        ]);

        $settings = $this->brandSettingsService->updateHeroFit($data, $request->user());

        return ApiResponse::success($this->brandSettingsService->payload($settings), 'Encadre del login actualizado');
    }

    public function updateHeroCopy(Request $request): JsonResponse
    {
        abort_unless($request->user()?->hasPermission('settings.update'), Response::HTTP_FORBIDDEN);

        $feature = [
            'icon' => ['required', 'string', 'in:'.implode(',', AppSetting::HERO_ICONS)],
            'title' => ['required', 'string', 'max:60'],
            'desc' => ['required', 'string', 'max:160'],
        ];
        $variant = [
            'line1' => ['required', 'string', 'max:80'],
            'line2' => ['required', 'string', 'max:80'],
            'subtitle' => ['required', 'string', 'max:240'],
            'features' => ['required', 'array', 'size:3'],
            'features.0' => ['required', 'array'],
            'features.1' => ['required', 'array'],
            'features.2' => ['required', 'array'],
            'features.*.icon' => $feature['icon'],
            'features.*.title' => $feature['title'],
            'features.*.desc' => $feature['desc'],
            'fit.x' => ['required', 'numeric', 'between:0,100'],
            'fit.y' => ['required', 'numeric', 'between:0,100'],
            'fit.zoom' => ['required', 'numeric', 'between:1,2.5'],
        ];

        $data = $request->validate([
            'desktop' => ['required', 'array'],
            'mobile' => ['required', 'array'],
            ...$this->prefixRules('desktop', $variant),
            ...$this->prefixRules('mobile', $variant),
        ]);

        $settings = $this->brandSettingsService->updateHeroCopy($data, $request->user());

        return ApiResponse::success($this->brandSettingsService->payload($settings), 'Portada del login actualizada');
    }

    /**
     * @param  array<string, mixed>  $rules
     * @return array<string, mixed>
     */
    private function prefixRules(string $prefix, array $rules): array
    {
        $prefixed = [];
        foreach ($rules as $key => $rule) {
            $prefixed[$prefix.'.'.$key] = $rule;
        }

        return $prefixed;
    }

    public function upload(Request $request, string $asset): JsonResponse
    {
        abort_unless($request->user()?->hasPermission('settings.update'), Response::HTTP_FORBIDDEN);
        abort_unless(AppSetting::isAssetKey($asset), Response::HTTP_NOT_FOUND);

        $maxKilobytes = $asset === AppSetting::ASSET_LOGIN_HERO ? 8192 : 5120;
        $request->validate([
            'image' => ['required', 'image', 'mimes:jpg,jpeg,png,webp', 'max:'.$maxKilobytes],
        ]);

        $settings = $this->brandSettingsService->storeAsset(
            $asset,
            $request->file('image'),
            $request->user(),
        );

        return ApiResponse::success($this->brandSettingsService->payload($settings), 'Imagen actualizada');
    }

    public function reset(Request $request, string $asset): JsonResponse
    {
        abort_unless($request->user()?->hasPermission('settings.update'), Response::HTTP_FORBIDDEN);
        abort_unless(AppSetting::isAssetKey($asset), Response::HTTP_NOT_FOUND);

        $settings = $this->brandSettingsService->resetAsset($asset, $request->user());

        return ApiResponse::success($this->brandSettingsService->payload($settings), 'Imagen restaurada');
    }

    public function updateLoader(Request $request, string $key): JsonResponse
    {
        abort_unless($request->user()?->hasPermission('settings.update'), Response::HTTP_FORBIDDEN);
        abort_unless(AppSetting::isLoaderKey($key), Response::HTTP_NOT_FOUND);

        $hex = ['required', 'string', 'regex:/^#(?:[0-9a-fA-F]{3}|[0-9a-fA-F]{6})$/'];
        $data = $request->validate([
            'ring_top' => $hex,
            'ring_right' => $hex,
            'glow' => $hex,
            'label_color' => $hex,
            'logo_animation' => ['required', 'in:'.implode(',', AppSetting::LOGO_ANIMATIONS)],
            'ring_animation' => ['required', 'in:'.implode(',', AppSetting::RING_ANIMATIONS)],
            'speed' => ['required', 'in:'.implode(',', AppSetting::LOADER_SPEEDS)],
        ]);

        $settings = $this->brandSettingsService->updateLoader($key, $data, $request->user());

        return ApiResponse::success($this->brandSettingsService->payload($settings), 'Cargador actualizado');
    }

    public function uploadLoaderLogo(Request $request, string $key): JsonResponse
    {
        abort_unless($request->user()?->hasPermission('settings.update'), Response::HTTP_FORBIDDEN);
        abort_unless(AppSetting::isLoaderKey($key), Response::HTTP_NOT_FOUND);

        $request->validate([
            'image' => ['required', 'image', 'mimes:jpg,jpeg,png,webp', 'max:5120'],
        ]);

        $settings = $this->brandSettingsService->storeLoaderLogo($key, $request->file('image'), $request->user());

        return ApiResponse::success($this->brandSettingsService->payload($settings), 'Logo del cargador actualizado');
    }

    public function resetLoaderLogo(Request $request, string $key): JsonResponse
    {
        abort_unless($request->user()?->hasPermission('settings.update'), Response::HTTP_FORBIDDEN);
        abort_unless(AppSetting::isLoaderKey($key), Response::HTTP_NOT_FOUND);

        $settings = $this->brandSettingsService->resetLoaderLogo($key, $request->user());

        return ApiResponse::success($this->brandSettingsService->payload($settings), 'Logo restaurado');
    }

    public function resetLoader(Request $request, string $key): JsonResponse
    {
        abort_unless($request->user()?->hasPermission('settings.update'), Response::HTTP_FORBIDDEN);
        abort_unless(AppSetting::isLoaderKey($key), Response::HTTP_NOT_FOUND);

        $settings = $this->brandSettingsService->resetLoader($key, $request->user());

        return ApiResponse::success($this->brandSettingsService->payload($settings), 'Cargador restaurado');
    }
}
