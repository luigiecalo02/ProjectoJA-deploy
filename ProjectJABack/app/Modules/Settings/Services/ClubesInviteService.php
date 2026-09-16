<?php

namespace App\Modules\Settings\Services;

use App\Models\User;
use App\Modules\Auth\Services\ClubesTenantAccess;
use App\Modules\Auth\Services\SessionContextService;
use App\Modules\Clubs\Models\Persona;
use App\Modules\Organizations\Models\Organizacion;
use App\Modules\Organizations\Models\PersonaOrganizacion;
use App\Modules\Organizations\Models\PersonaOrganizacionRol;
use App\Modules\Users\Models\Permission;
use App\Modules\Users\Models\Role;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\Response;

final class ClubesInviteService
{
    private const TTL_DAYS = 30;

    public function __construct(
        private readonly ClubesTenantAccess $tenant,
        private readonly SessionContextService $sessionContext,
    ) {}

    /**
     * @return array{url: string, expires_at: string, organizacion_id: int, organizacion_nombre: string}
     */
    public function createLink(User $actor, string $origin): array
    {
        $org = $this->directorOrganization($actor);
        $expires = now()->addDays(self::TTL_DAYS);
        $token = Crypt::encryptString(json_encode([
            'o' => (int) $org->id,
            'e' => $expires->timestamp,
            'n' => Str::random(16),
        ], JSON_THROW_ON_ERROR));

        return [
            'url' => rtrim($origin, '/').'/activar?token='.rawurlencode($token),
            'expires_at' => $expires->toIso8601String(),
            'organizacion_id' => (int) $org->id,
            'organizacion_nombre' => $org->nombre,
        ];
    }

    /**
     * @return array{organizacion_id: int, organizacion_nombre: string, expires_at: string}
     */
    public function show(string $token): array
    {
        $payload = $this->decode($token);
        $org = $this->orgInTenant((int) $payload['o']);

        return [
            'organizacion_id' => (int) $org->id,
            'organizacion_nombre' => $org->nombre,
            'expires_at' => now()->setTimestamp((int) $payload['e'])->toIso8601String(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function lookup(string $token, string $identificacion): array
    {
        $org = $this->orgInTenant((int) $this->decode($token)['o']);
        $persona = $this->personaInOrg($org, $identificacion);

        $fields = [
            'tipo_identificacion' => $persona->tipo_identificacion,
            'identificacion' => $persona->identificacion,
            'nombre1' => $persona->nombre1,
            'nombre2' => $persona->nombre2,
            'apellido1' => $persona->apellido1,
            'apellido2' => $persona->apellido2,
            'correo' => $persona->correo,
            'telefono' => $persona->telefono,
            'sexo' => $persona->sexo,
            'fecha_nacimiento' => $persona->fecha_nacimiento?->toDateString(),
        ];

        $missing = [];
        foreach (['nombre1', 'apellido1', 'correo'] as $key) {
            if (! filled($fields[$key] ?? null)) {
                $missing[] = $key;
            }
        }

        return [
            'organizacion_id' => (int) $org->id,
            'organizacion_nombre' => $org->nombre,
            'persona' => $fields,
            'missing' => $missing,
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array{token: string, user: User}
     */
    public function activate(array $data): array
    {
        $org = $this->orgInTenant((int) $this->decode((string) $data['token'])['o']);
        $persona = $this->personaInOrg($org, (string) $data['identificacion']);
        $role = $this->memberRole();

        $email = strtolower(trim((string) ($data['correo'] ?? $persona->correo ?? '')));
        if ($email === '') {
            throw ValidationException::withMessages([
                'correo' => ['El correo es obligatorio para crear la cuenta.'],
            ]);
        }

        if (User::withTrashed()->where('email', $email)->where(function ($query) use ($persona): void {
            $query->whereNull('persona_id')->orWhere('persona_id', '!=', $persona->id);
        })->exists()) {
            throw ValidationException::withMessages([
                'correo' => ['Ya existe una cuenta con este correo.'],
            ]);
        }

        $user = DB::transaction(function () use ($data, $persona, $org, $role, $email): User {
            $persona->fill([
                'nombre1' => filled($persona->nombre1) ? $persona->nombre1 : trim((string) ($data['nombre1'] ?? '')),
                'nombre2' => filled($persona->nombre2) ? $persona->nombre2 : (filled($data['nombre2'] ?? null) ? trim((string) $data['nombre2']) : null),
                'apellido1' => filled($persona->apellido1) ? $persona->apellido1 : trim((string) ($data['apellido1'] ?? '')),
                'apellido2' => filled($persona->apellido2) ? $persona->apellido2 : (filled($data['apellido2'] ?? null) ? trim((string) $data['apellido2']) : null),
                'correo' => $email,
                'telefono' => filled($persona->telefono) ? $persona->telefono : (filled($data['telefono'] ?? null) ? trim((string) $data['telefono']) : null),
                'sexo' => filled($persona->sexo) ? $persona->sexo : ($data['sexo'] ?? null),
                'fecha_nacimiento' => $persona->fecha_nacimiento ?: ($data['fecha_nacimiento'] ?? null),
            ]);
            if (! filled($persona->nombre1) || ! filled($persona->apellido1)) {
                throw ValidationException::withMessages([
                    'nombre1' => ['Completa el nombre y el apellido.'],
                ]);
            }
            $persona->save();

            $membership = PersonaOrganizacion::query()
                ->where('persona_id', $persona->id)
                ->where('organizacion_id', $org->id)
                ->first();

            PersonaOrganizacionRol::query()->firstOrCreate(
                [
                    'persona_organizacion_id' => $membership->id,
                    'rol_id' => $role->id,
                ],
                [
                    'created_at' => now(),
                ],
            );

            $user = User::query()->create([
                'persona_id' => $persona->id,
                'name' => $persona->fresh()?->full_name ?: $email,
                'email' => $email,
                'password' => $data['password'],
                'is_active' => true,
                'email_verified_at' => now(),
                'active_organizacion_id' => $org->id,
                'active_rol_id' => $role->id,
            ]);

            return $this->sessionContext->pinOrganization($user, (int) $org->id);
        });

        return [
            'token' => $user->createToken('api')->plainTextToken,
            'user' => $user,
        ];
    }

    /**
     * @return array{o: int, e: int}
     */
    private function decode(string $token): array
    {
        try {
            $data = json_decode(Crypt::decryptString($token), true, 512, JSON_THROW_ON_ERROR);
        } catch (\Throwable) {
            throw ValidationException::withMessages([
                'token' => ['El enlace no es válido.'],
            ]);
        }

        if (! is_array($data) || (int) ($data['e'] ?? 0) < now()->timestamp) {
            throw ValidationException::withMessages([
                'token' => ['El enlace expiró. Pídele uno nuevo al director.'],
            ]);
        }

        return [
            'o' => (int) ($data['o'] ?? 0),
            'e' => (int) $data['e'],
        ];
    }

    private function directorOrganization(User $actor): Organizacion
    {
        abort_unless(
            in_array('director', $actor->roleNames(), true),
            Response::HTTP_FORBIDDEN,
            'Solo el director puede generar el enlace de activación.',
        );

        $orgId = (int) ($actor->active_organizacion_id ?? 0);
        abort_unless($orgId > 0, Response::HTTP_FORBIDDEN, 'Elige el contexto de tu club para generar el enlace.');

        return $this->orgInTenant($orgId);
    }

    private function orgInTenant(int $id): Organizacion
    {
        $this->tenant->assertInTenant($id);
        $org = Organizacion::query()->find($id);
        if (! $org) {
            throw ValidationException::withMessages([
                'token' => ['No se encontró la organización de este enlace.'],
            ]);
        }

        return $org;
    }

    private function personaInOrg(Organizacion $org, string $identificacion): Persona
    {
        $documento = trim($identificacion);
        $persona = Persona::query()
            ->where('identificacion', $documento)
            ->first();

        if (! $persona) {
            throw ValidationException::withMessages([
                'identificacion' => ['No hay una persona con esa identificación en este club.'],
            ]);
        }

        $member = PersonaOrganizacion::query()
            ->where('persona_id', $persona->id)
            ->where('organizacion_id', $org->id)
            ->where('estado', true)
            ->first();

        if (! $member) {
            throw ValidationException::withMessages([
                'identificacion' => ['Esa persona no pertenece a esta organización.'],
            ]);
        }

        if (User::withTrashed()->where('persona_id', $persona->id)->exists()) {
            throw ValidationException::withMessages([
                'identificacion' => ['Esta persona ya tiene una cuenta. Inicia sesión o recupera la contraseña.'],
            ]);
        }

        return $persona;
    }

    private function memberRole(): Role
    {
        $role = Role::query()->updateOrCreate(
            ['name' => 'miembro'],
            [
                'display_name' => 'Miembro',
                'description' => 'Integrante del club con acceso a su organización',
                'is_system' => true,
                'is_super' => false,
                'estado' => true,
                'sort_order' => 11,
            ],
        );

        if ($role->permissions()->count() === 0) {
            $role->permissions()->sync(
                Permission::query()->whereIn('name', [
                    'dashboard.view',
                    'events.view',
                    'mi_club.view',
                    'asistencia.view',
                    'seguros_consulta.view',
                ])->pluck('id')
            );
        }

        return $role;
    }
}
