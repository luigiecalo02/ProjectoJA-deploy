<?php

namespace App\Modules\Auth\Http\Controllers;

use App\Modules\Auth\Services\AccountMailService;
use App\Modules\Shared\Http\Responses\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rules\Password;

final class AccountMailController
{
    public function __construct(private readonly AccountMailService $accountMail) {}

    public function forgot(Request $request): JsonResponse
    {
        $data = $request->validate([
            'email' => ['required_without:identificacion', 'nullable', 'email', 'max:255'],
            'identificacion' => ['required_without:email', 'nullable', 'string', 'max:80'],
        ]);

        $result = $this->accountMail->requestPasswordReset(
            $data['email'] ?? null,
            $data['identificacion'] ?? null,
        );

        return ApiResponse::success($result, 'Enviamos el enlace de recuperación a '.$result['email'].'. Si no lo encuentras, revisa la bandeja de spam.');
    }

    public function reset(Request $request): JsonResponse
    {
        $data = $request->validate([
            'email' => ['required', 'email', 'max:255'],
            'token' => ['required', 'string'],
            'password' => ['required', 'confirmed', Password::defaults()],
        ]);

        $this->accountMail->resetPassword($data);

        return ApiResponse::success(null, 'Contraseña actualizada. Ya puedes iniciar sesión.');
    }

    public function verify(Request $request): JsonResponse
    {
        $data = $request->validate([
            'id' => ['required', 'integer'],
            'hash' => ['required', 'string'],
        ]);

        $this->accountMail->verify((int) $data['id'], $data['hash']);

        return ApiResponse::success(null, 'Cuenta confirmada.');
    }

    public function verifyCode(Request $request): JsonResponse
    {
        $data = $request->validate([
            'email' => ['required', 'email', 'max:255'],
            'code' => ['required', 'digits:6'],
        ]);

        $this->accountMail->verifyCode($data['email'], $data['code']);

        return ApiResponse::success(null, 'Cuenta confirmada.');
    }

    public function resend(Request $request): JsonResponse
    {
        $data = $request->validate([
            'email' => ['required', 'email', 'max:255'],
        ]);

        $this->accountMail->resend($data['email']);

        return ApiResponse::success(null, 'Si el correo existe y no está confirmado, enviamos el enlace. Si no lo encuentras, revisa la bandeja de spam.');
    }

    public function updatePendingEmail(Request $request): JsonResponse
    {
        $data = $request->validate([
            'email' => ['required', 'email', 'max:255'],
            'identificacion' => ['required', 'string', 'max:80'],
            'new_email' => ['required', 'email', 'max:255', 'different:email'],
        ]);

        $result = $this->accountMail->updatePendingEmail(
            $data['email'],
            $data['identificacion'],
            $data['new_email'],
        );

        return ApiResponse::success($result, 'Correo actualizado. Enviamos el enlace de confirmación a '.$result['email'].'. Si no lo encuentras, revisa la bandeja de spam.');
    }

    public function recover(Request $request): JsonResponse
    {
        $data = $request->validate([
            'email' => ['required_without:identificacion', 'nullable', 'email', 'max:255'],
            'identificacion' => ['required_without:email', 'nullable', 'string', 'max:80'],
        ]);

        $result = $this->accountMail->recover(
            $data['email'] ?? null,
            $data['identificacion'] ?? null,
        );

        $message = $result['already_verified']
            ? 'Esta cuenta ya está confirmada.'
            : 'Enviamos el código de verificación a '.$result['email_masked'].'.';

        return ApiResponse::success($result, $message);
    }
}
