<?php

namespace App\Modules\Settings\Http\Controllers;

use App\Models\User;
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
        $this->assertCanView($request);

        return ApiResponse::success($this->mailSettings->publicConfig($this->isClubesClient($request)));
    }

    public function update(Request $request): JsonResponse
    {
        $this->assertCanUpdate($request);

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
        $this->assertCanUpdate($request);

        $data = $request->validate([
            'to' => ['required', 'email', 'max:255'],
        ]);

        $this->mailSettings->sendTest($data['to']);

        return ApiResponse::success(null, 'Correo de prueba enviado');
    }

    private function assertCanView(Request $request): void
    {
        $user = $request->user();
        abort_unless($user, Response::HTTP_FORBIDDEN);
        if ($user->hasPermission('settings.view') || $user->hasPermission('settings.update')) {
            return;
        }
        abort_unless($this->clubesDirector($request, $user), Response::HTTP_FORBIDDEN);
    }

    private function assertCanUpdate(Request $request): void
    {
        $user = $request->user();
        abort_unless($user, Response::HTTP_FORBIDDEN);
        if ($user->hasPermission('settings.update')) {
            return;
        }
        abort_unless($this->clubesDirector($request, $user), Response::HTTP_FORBIDDEN);
    }

    private function clubesDirector(Request $request, User $user): bool
    {
        return $this->isClubesClient($request)
            && in_array('director', $user->roleNames(), true);
    }

    private function isClubesClient(Request $request): bool
    {
        return $request->header('X-Clubes-Client') === 'clubes';
    }
}
