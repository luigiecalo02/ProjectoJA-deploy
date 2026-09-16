<?php

namespace App\Modules\Settings\Http\Controllers;

use App\Modules\Settings\Services\ClubesInviteService;
use App\Modules\Settings\Services\ClubesPublicSignupService;
use App\Modules\Shared\Http\Responses\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rules\Password;

final class ClubesSignupController
{
    public function __construct(
        private readonly ClubesPublicSignupService $signup,
        private readonly ClubesInviteService $invite,
    ) {}

    public function organizaciones(Request $request): JsonResponse
    {
        $this->assertClubesClient($request);
        $data = $request->validate([
            'padre_id' => ['nullable', 'integer', 'min:1'],
        ]);

        return ApiResponse::success(
            $this->signup->browse(isset($data['padre_id']) ? (int) $data['padre_id'] : null)
        );
    }

    public function register(Request $request): JsonResponse
    {
        $this->assertClubesClient($request);
        $data = $request->validate([
            'organizacion_id' => ['required', 'integer', 'min:1'],
            'nombre1' => ['required', 'string', 'max:80'],
            'apellido1' => ['required', 'string', 'max:80'],
            'correo' => ['required', 'email', 'max:160'],
            'password' => ['required', 'string', 'min:8', 'confirmed'],
            'password_confirmation' => ['required', 'string'],
            'tipo_identificacion' => ['required', 'string', 'in:CC,TI,CE,PA'],
            'identificacion' => ['required', 'string', 'max:30'],
            'telefono' => ['nullable', 'string', 'max:30'],
            'sexo' => ['nullable', 'in:M,F'],
        ], [
            'password.confirmed' => 'Las contraseñas no coinciden.',
            'password.min' => 'La contraseña debe tener al menos 8 caracteres.',
            'password_confirmation.required' => 'Confirma la contraseña.',
        ]);

        $result = $this->signup->register($data);

        return ApiResponse::success(
            $result,
            $result['sent']
                ? 'Revisa tu correo y confirma la cuenta. Mientras no lo hagas, permanecerá inactiva.'
                : 'Cuenta creada. El administrador debe configurar el correo para enviarte la confirmación.',
            201,
        );
    }

    public function createInvite(Request $request): JsonResponse
    {
        $this->assertClubesClient($request);
        return ApiResponse::success(
            $this->invite->createLink($request->user(), $this->frontOrigin($request)),
            'Enlace de activación listo.',
        );
    }

    public function inviteShow(Request $request): JsonResponse
    {
        $this->assertClubesClient($request);
        $data = $request->validate([
            'token' => ['required', 'string'],
        ]);

        return ApiResponse::success($this->invite->show($data['token']));
    }

    public function inviteLookup(Request $request): JsonResponse
    {
        $this->assertClubesClient($request);
        $data = $request->validate([
            'token' => ['required', 'string'],
            'identificacion' => ['required', 'string', 'max:80'],
        ]);

        return ApiResponse::success(
            $this->invite->lookup($data['token'], $data['identificacion'])
        );
    }

    public function inviteActivate(Request $request): JsonResponse
    {
        $this->assertClubesClient($request);
        $data = $request->validate([
            'token' => ['required', 'string'],
            'identificacion' => ['required', 'string', 'max:80'],
            'tipo_identificacion' => ['nullable', 'string', 'in:CC,TI,CE,PA'],
            'nombre1' => ['required', 'string', 'max:80'],
            'nombre2' => ['nullable', 'string', 'max:80'],
            'apellido1' => ['required', 'string', 'max:80'],
            'apellido2' => ['nullable', 'string', 'max:80'],
            'correo' => ['required', 'email', 'max:160'],
            'telefono' => ['nullable', 'string', 'max:30'],
            'sexo' => ['nullable', 'in:M,F'],
            'fecha_nacimiento' => ['nullable', 'date'],
            'password' => ['nullable', 'confirmed', Password::defaults()],
            'password_confirmation' => ['nullable', 'string'],
        ], [
            'password.confirmed' => 'Las contraseñas no coinciden.',
        ]);

        $issued = $this->invite->activate($data);

        return ApiResponse::success([
            'token' => $issued['token'],
            'token_type' => 'Bearer',
        ], 'Cuenta activada. Ya estás en tu organización.', 201);
    }

    private function frontOrigin(Request $request): string
    {
        $origin = $request->headers->get('Origin');
        if (is_string($origin) && $origin !== '') {
            return rtrim($origin, '/');
        }

        $referer = $request->headers->get('Referer');
        if (is_string($referer) && $referer !== '') {
            $parts = parse_url($referer);
            if (! empty($parts['scheme']) && ! empty($parts['host'])) {
                $port = isset($parts['port']) ? ':'.$parts['port'] : '';

                return $parts['scheme'].'://'.$parts['host'].$port;
            }
        }

        return rtrim((string) config('app.url'), '/');
    }

    private function assertClubesClient(Request $request): void
    {
        abort_unless(
            $request->header('X-Clubes-Client') === 'clubes',
            403,
            'El registro solo está disponible en Clubes.',
        );
    }
}
