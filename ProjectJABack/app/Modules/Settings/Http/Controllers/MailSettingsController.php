<?php

namespace App\Modules\Settings\Http\Controllers;

use App\Modules\Settings\Services\MailSettingsService;
use App\Modules\Shared\Http\Responses\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final class MailSettingsController
{
    public function __construct(private readonly MailSettingsService $mailSettings) {}

    public function show(Request $request): JsonResponse
    {
        abort_unless($request->user()?->hasPermission('settings.view'), Response::HTTP_FORBIDDEN);

        return ApiResponse::success($this->mailSettings->publicConfig());
    }

    public function update(Request $request): JsonResponse
    {
        abort_unless($request->user()?->hasPermission('settings.update'), Response::HTTP_FORBIDDEN);

        $data = $request->validate([
            'host' => ['required', 'string', 'max:255'],
            'port' => ['required', 'integer', 'min:1', 'max:65535'],
            'encryption' => ['required', 'string', 'in:tls,ssl,none'],
            'username' => ['required', 'string', 'max:255'],
            'password' => ['nullable', 'string', 'max:255'],
            'from_address' => ['required', 'email', 'max:255'],
            'from_name' => ['required', 'string', 'max:120'],
        ]);

        return ApiResponse::success($this->mailSettings->update($data, $request->user()), 'Correo actualizado');
    }

    public function test(Request $request): JsonResponse
    {
        abort_unless($request->user()?->hasPermission('settings.update'), Response::HTTP_FORBIDDEN);

        $data = $request->validate([
            'to' => ['required', 'email', 'max:255'],
        ]);

        $this->mailSettings->sendTest($data['to']);

        return ApiResponse::success(null, 'Correo de prueba enviado');
    }
}
