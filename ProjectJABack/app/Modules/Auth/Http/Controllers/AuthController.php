<?php

namespace App\Modules\Auth\Http\Controllers;

use App\Models\User;
use App\Modules\Auth\Http\Requests\LoginRequest;
use App\Modules\Auth\Services\AuthService;
use App\Modules\Auth\Services\SessionContextService;
use App\Modules\Organizations\Services\OrganizationAccessService;
use App\Modules\Shared\Http\Responses\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Laravel\Socialite\Facades\Socialite;
use Symfony\Component\HttpFoundation\Response;

final class AuthController
{
    public function __construct(
        private readonly AuthService $authService,
        private readonly OrganizationAccessService $orgAccess,
        private readonly SessionContextService $sessionContext,
    ) {}

    public function login(LoginRequest $request): JsonResponse
    {
        $result = $this->authService->login(
            $request->string('email')->toString(),
            $request->string('password')->toString(),
        );

        $user = $this->sessionContext->ensureContext($result['user']);

        return ApiResponse::success([
            'token' => $result['token'],
            'token_type' => 'Bearer',
            'user' => $this->userPayload($user),
        ], 'Inicio de sesión exitoso');
    }

    public function logout(Request $request): JsonResponse
    {
        $this->authService->logout($request->user());

        return ApiResponse::success(null, 'Sesión cerrada');
    }

    public function me(Request $request): JsonResponse
    {
        $user = $this->sessionContext->ensureContext(
            $request->user()->load(['persona'])
        );

        return ApiResponse::success($this->userPayload($user));
    }

    public function contextOptions(Request $request): JsonResponse
    {
        $user = $request->user();

        return ApiResponse::success([
            'requires_context' => $this->sessionContext->requiresSelection($user),
            'contexto' => $this->sessionContext->current($user),
            'options' => $this->sessionContext->options($user),
        ]);
    }

    public function setContext(Request $request): JsonResponse
    {
        $data = $request->validate([
            'organizacion_id' => ['nullable', 'integer', 'exists:organizacion,id'],
            'rol_id' => ['required', 'integer', 'exists:roles,id'],
        ]);

        $user = $this->sessionContext->setContext(
            $request->user(),
            isset($data['organizacion_id']) ? (int) $data['organizacion_id'] : null,
            (int) $data['rol_id'],
        );

        return ApiResponse::success(
            $this->userPayload($user),
            'Contexto de sesión actualizado'
        );
    }

    public function clearContext(Request $request): JsonResponse
    {
        $user = $this->sessionContext->clearContext($request->user());

        return ApiResponse::success(
            $this->userPayload($user),
            'Contexto de sesión limpiado'
        );
    }

    public function impersonate(Request $request, User $user): JsonResponse
    {
        abort_unless($request->user()->can('impersonate', $user), Response::HTTP_FORBIDDEN);

        $result = $this->authService->impersonate($request->user(), $user);
        $target = $this->sessionContext->ensureContext($result['user']);

        return ApiResponse::success([
            'token' => $result['token'],
            'token_type' => 'Bearer',
            'user' => $this->userPayload($target, $result['impersonator']),
        ], 'Sesión iniciada como el usuario seleccionado');
    }

    public function stopImpersonation(Request $request): JsonResponse
    {
        $result = $this->authService->stopImpersonation($request->user());
        $actor = $this->sessionContext->ensureContext($result['user']);

        return ApiResponse::success([
            'token' => $result['token'],
            'token_type' => 'Bearer',
            'user' => $this->userPayload($actor),
        ], 'Volviste a tu usuario');
    }

    public function redirect(string $provider): RedirectResponse|JsonResponse
    {
        if (! in_array($provider, ['google', 'facebook'], true)) {
            return ApiResponse::error('Proveedor no soportado', Response::HTTP_BAD_REQUEST);
        }

        return Socialite::driver($provider)->stateless()->redirect();
    }

    public function callback(string $provider): RedirectResponse|JsonResponse
    {
        if (! in_array($provider, ['google', 'facebook'], true)) {
            return ApiResponse::error('Proveedor no soportado', Response::HTTP_BAD_REQUEST);
        }

        $socialUser = Socialite::driver($provider)->stateless()->user();
        $result = $this->authService->loginWithSocial($provider, $socialUser);

        $front = rtrim(config('app.frontend_url', env('FRONTEND_URL', 'http://localhost:5173')), '/');
        $token = urlencode($result['token']);

        return redirect()->away("{$front}/auth/callback?token={$token}");
    }

    private function userPayload(User $user, ?User $impersonator = null): array
    {
        $resolved = $impersonator ?? $this->resolveImpersonator($user);

        return [
            'id' => $user->id,
            'name' => $user->name,
            'email' => $user->email,
            'avatar_url' => $this->publicFileUrl($user->avatar_url),
            'is_active' => $user->is_active,
            'is_super' => $user->isSuperAdmin(),
            'is_admin' => (bool) $user->is_admin,
            'persona_id' => $user->persona_id,
            'roles' => $user->roleNames(),
            'permissions' => $user->permissionNames(),
            'organizaciones' => $this->orgAccess->sessionOrganizaciones($user),
            'organizacion_ids' => $this->orgAccess->accessibleOrganizationIds($user),
            'contexto' => $this->sessionContext->current($user),
            'requires_context' => $this->sessionContext->requiresSelection($user),
            'context_options' => $this->sessionContext->options($user),
            'impersonated' => $resolved !== null,
            'impersonator' => $resolved ? [
                'id' => $resolved->id,
                'name' => $resolved->name,
                'email' => $resolved->email,
            ] : null,
        ];
    }

    private function resolveImpersonator(User $user): ?User
    {
        $id = $this->authService->impersonatorIdFromUser($user);

        return $id ? User::query()->find($id) : null;
    }

    private function publicFileUrl(?string $value): ?string
    {
        if (! is_string($value) || $value === '') {
            return null;
        }

        if (str_starts_with($value, 'http://') || str_starts_with($value, 'https://')) {
            return $value;
        }

        $path = ltrim($value, '/');
        if (str_starts_with($path, 'storage/')) {
            return url($path);
        }

        return url('storage/'.$path);
    }
}
