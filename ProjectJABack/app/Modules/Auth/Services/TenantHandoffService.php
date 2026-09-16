<?php

namespace App\Modules\Auth\Services;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Salto de sesión entre fronts Clubes (subdominios) sin volver a pedir contraseña.
 */
final class TenantHandoffService
{
    private const TTL_SECONDS = 60;

    public function __construct(
        private readonly SessionContextService $sessionContext,
        private readonly ClubesTenantAccess $clubesTenant,
    ) {}

    /**
     * @return array{code: string, host: string, origin: string}
     */
    public function issue(User $user, ?int $organizacionId, int $rolId, Request $request): array
    {
        if ($this->membership($user, $organizacionId, $rolId) === null) {
            throw ValidationException::withMessages([
                'contexto' => ['La organización y el rol seleccionados no están disponibles para tu usuario.'],
            ]);
        }

        $origin = $this->clubesTenant->originForOrganization($organizacionId, $request);
        $host = $this->clubesTenant->hostForOrganization($organizacionId, $request);
        if ($origin === null || $host === null) {
            throw ValidationException::withMessages([
                'contexto' => ['Ese club no tiene un dominio configurado para abrir la sesión ahí.'],
            ]);
        }

        $code = Str::lower(Str::random(40));
        Cache::put($this->cacheKey($code), [
            'user_id' => (int) $user->id,
            'organizacion_id' => $organizacionId,
            'rol_id' => $rolId,
        ], now()->addSeconds(self::TTL_SECONDS));

        return [
            'code' => $code,
            'host' => $host,
            'origin' => $origin,
        ];
    }

    /**
     * @return array{user: User, token: string}
     */
    public function consume(string $code, Request $request): array
    {
        $payload = Cache::get($this->cacheKey($code));
        if (! is_array($payload) || ! isset($payload['user_id'], $payload['rol_id'])) {
            throw ValidationException::withMessages([
                'code' => ['El enlace para cambiar de club expiró. Vuelve a elegirlo en el menú.'],
            ]);
        }

        $user = User::query()->find((int) $payload['user_id']);
        if (! $user || ! $user->is_active) {
            throw ValidationException::withMessages([
                'code' => ['La cuenta ya no está disponible.'],
            ]);
        }

        $organizacionId = isset($payload['organizacion_id']) ? (int) $payload['organizacion_id'] : null;
        $organizacionId = $organizacionId && $organizacionId > 0 ? $organizacionId : null;
        $rolId = (int) $payload['rol_id'];

        $this->clubesTenant->assertUserMayEnter($user, $request);
        if ($organizacionId !== null) {
            $this->clubesTenant->assertInTenant($organizacionId, $request);
        }

        if (is_string($payload['token'] ?? null) && $payload['token'] !== '') {
            return ['user' => $this->sessionContext->setContext($user, $organizacionId, $rolId), 'token' => $payload['token']];
        }

        $token = $user->createToken('api')->plainTextToken;
        $user = $this->sessionContext->setContext($user, $organizacionId, $rolId);

        Cache::put($this->cacheKey($code), [
            ...$payload,
            'token' => $token,
        ], now()->addSeconds(self::TTL_SECONDS));

        return ['user' => $user, 'token' => $token];
    }

    /**
     * @return array<string, mixed>|null
     */
    private function membership(User $user, ?int $organizacionId, int $rolId): ?array
    {
        foreach ($this->sessionContext->memberships($user) as $option) {
            $optionOrg = $option['organizacion_id'] ?? null;
            if ($optionOrg === $organizacionId && (int) $option['rol_id'] === $rolId) {
                return $option;
            }
        }

        return null;
    }

    private function cacheKey(string $code): string
    {
        return 'clubes:handoff:'.$code;
    }
}
