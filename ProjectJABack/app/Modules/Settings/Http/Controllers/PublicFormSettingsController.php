<?php

namespace App\Modules\Settings\Http\Controllers;

use App\Modules\Settings\Services\PublicFormSettingsService;
use App\Modules\Shared\Http\Responses\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final class PublicFormSettingsController
{
    public function __construct(private readonly PublicFormSettingsService $publicForm) {}

    public function show(Request $request): JsonResponse
    {
        abort_unless($request->user()?->hasPermission('settings.view'), Response::HTTP_FORBIDDEN);

        return ApiResponse::success($this->publicForm->get());
    }

    public function update(Request $request): JsonResponse
    {
        abort_unless($request->user()?->hasPermission('settings.update'), Response::HTTP_FORBIDDEN);

        $data = $request->validate([
            'enabled' => ['required', 'boolean'],
            'allow_request_asociacion' => ['required', 'boolean'],
            'allow_request_distrito' => ['required', 'boolean'],
            'allow_request_iglesia' => ['required', 'boolean'],
            'allow_request_club' => ['required', 'boolean'],
        ]);

        return ApiResponse::success($this->publicForm->update($data, $request->user()), 'Formulario público actualizado');
    }
}
