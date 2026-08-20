<?php

namespace App\Modules\Auth\Services;

use App\Models\User;
use App\Modules\Shared\Services\AuditLogger;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;
use Laravel\Socialite\Contracts\User as SocialiteUser;

final class AuthService
{
    public function __construct(private readonly AuditLogger $auditLogger) {}

    /**
     * @return array{user: User, token: string}
     */
    public function login(string $email, string $password): array
    {
        $user = User::query()->where('email', $email)->first();

        if (! $user || ! $user->password || ! Hash::check($password, $user->password)) {
            throw ValidationException::withMessages([
                'email' => ['Credenciales incorrectas.'],
            ]);
        }

        if (! $user->email_verified_at) {
            throw ValidationException::withMessages([
                'email' => ['Confirma tu correo con el enlace que te enviamos antes de entrar. Si no lo ves, revisa la bandeja de spam.'],
            ]);
        }

        if (! $user->is_active) {
            throw ValidationException::withMessages([
                'email' => ['La cuenta está desactivada.'],
            ]);
        }

        $user->tokens()->delete();
        $token = $user->createToken('api')->plainTextToken;

        $this->auditLogger->log('auth', 'login', null, ['email' => $user->email], $user);

        return ['user' => $user, 'token' => $token];
    }

    public function logout(User $user): void
    {
        $user->currentAccessToken()?->delete();
        $this->auditLogger->log('auth', 'logout', null, ['email' => $user->email], $user);
    }

    /**
     * @return array{user: User, token: string}
     */
    public function loginWithSocial(string $provider, SocialiteUser $socialUser): array
    {
        $user = User::query()
            ->where('provider', $provider)
            ->where('provider_id', $socialUser->getId())
            ->first();

        if (! $user) {
            $user = User::query()->where('email', $socialUser->getEmail())->first();
        }

        if (! $user) {
            $user = User::query()->create([
                'name' => $socialUser->getName() ?: $socialUser->getNickname() ?: 'Usuario',
                'email' => $socialUser->getEmail(),
                'password' => null,
                'provider' => $provider,
                'provider_id' => $socialUser->getId(),
                'avatar_url' => $socialUser->getAvatar(),
                'email_verified_at' => now(),
                'is_active' => true,
            ]);
        } else {
            $user->update([
                'provider' => $provider,
                'provider_id' => $socialUser->getId(),
                'avatar_url' => $user->avatar_url ?: $socialUser->getAvatar(),
            ]);
        }

        if (! $user->is_active) {
            throw ValidationException::withMessages([
                'email' => ['La cuenta está desactivada.'],
            ]);
        }

        $user->tokens()->delete();
        $token = $user->createToken('api')->plainTextToken;

        $this->auditLogger->log('auth', 'oauth_login', null, [
            'provider' => $provider,
            'email' => $user->email,
        ], $user);

        return ['user' => $user, 'token' => $token];
    }
}
