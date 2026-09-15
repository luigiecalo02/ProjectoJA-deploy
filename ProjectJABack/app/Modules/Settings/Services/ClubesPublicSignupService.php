<?php

namespace App\Modules\Settings\Services;

use App\Models\User;
use App\Modules\Auth\Services\AccountMailService;
use App\Modules\Auth\Services\ClubesTenantAccess;
use App\Modules\Clubs\Models\Persona;
use App\Modules\Organizations\Models\Organizacion;
use App\Modules\Organizations\Models\PersonaOrganizacion;
use App\Modules\Organizations\Models\PersonaOrganizacionRol;
use App\Modules\Users\Models\Role;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class ClubesPublicSignupService
{
    public function __construct(
        private readonly ClubesTenantAccess $tenant,
        private readonly AccountMailService $accountMail,
    ) {}

    /**
     * @return array{path: list<array<string, mixed>>, children: list<array<string, mixed>>}
     */
    public function browse(?int $padreId = null): array
    {
        $root = $this->root();
        $current = $padreId ? $this->orgInTenant($padreId) : $root;

        return [
            'path' => $this->pathTo($current, $root),
            'children' => $this->childrenOf($current),
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array{email: string, email_masked: string, sent: bool}
     */
    public function register(array $data): array
    {
        $club = $this->orgInTenant((int) $data['organizacion_id']);
        if (! $club->isClubTipo()) {
            throw ValidationException::withMessages([
                'organizacion_id' => ['Debes elegir un club para registrarte.'],
            ]);
        }

        $email = strtolower(trim((string) $data['correo']));
        $tipo = strtoupper(trim((string) $data['tipo_identificacion']));
        $documento = trim((string) $data['identificacion']);

        if (User::withTrashed()->where('email', $email)->exists()) {
            throw ValidationException::withMessages([
                'correo' => ['Ya existe una cuenta con este correo.'],
            ]);
        }

        $roleId = (int) Role::query()->where('name', 'invitado')->where('estado', true)->value('id');
        if ($roleId <= 0) {
            throw ValidationException::withMessages([
                'organizacion_id' => ['El registro no está disponible temporalmente.'],
            ]);
        }

        DB::transaction(function () use ($data, $email, $tipo, $documento, $club, $roleId): void {
            $persona = Persona::query()
                ->where('tipo_identificacion', $tipo)
                ->where('identificacion', $documento)
                ->first();

            if ($persona && User::withTrashed()->where('persona_id', $persona->id)->exists()) {
                throw ValidationException::withMessages([
                    'identificacion' => ['Esta identificación ya tiene una cuenta.'],
                ]);
            }

            if (! $persona) {
                $persona = Persona::query()->create([
                    'tipo_identificacion' => $tipo,
                    'identificacion' => $documento,
                    'nombre1' => trim((string) $data['nombre1']),
                    'apellido1' => trim((string) $data['apellido1']),
                    'correo' => $email,
                    'telefono' => filled($data['telefono'] ?? null) ? trim((string) $data['telefono']) : null,
                    'sexo' => filled($data['sexo'] ?? null) ? $data['sexo'] : null,
                ]);
            }

            $membership = PersonaOrganizacion::query()->firstOrCreate(
                [
                    'persona_id' => $persona->id,
                    'organizacion_id' => $club->id,
                ],
                [
                    'fecha_inicio' => now()->toDateString(),
                    'estado' => true,
                ],
            );

            PersonaOrganizacionRol::query()->firstOrCreate(
                [
                    'persona_organizacion_id' => $membership->id,
                    'rol_id' => $roleId,
                ],
                [
                    'created_at' => now(),
                ],
            );

            User::query()->create([
                'persona_id' => $persona->id,
                'name' => $persona->full_name,
                'email' => $email,
                'password' => $data['password'],
                'is_active' => false,
                'email_verified_at' => null,
                'active_organizacion_id' => $club->id,
                'active_rol_id' => $roleId,
            ]);
        });

        $user = User::query()->where('email', $email)->firstOrFail();
        $sent = false;
        try {
            $sent = $this->accountMail->sendVerification($user, $club->id);
        } catch (\Throwable) {
            $sent = false;
        }

        return [
            'email' => $email,
            'email_masked' => $this->accountMail->maskEmail($email),
            'sent' => $sent,
        ];
    }

    private function root(): Organizacion
    {
        $root = Organizacion::query()
            ->with(['tipo:id,nombre', 'padre'])
            ->find($this->tenant->requireRootId());

        if (! $root) {
            throw ValidationException::withMessages([
                'organizacion_id' => ['No se encontró la organización del .env.'],
            ]);
        }

        return $root;
    }

    private function orgInTenant(int $id): Organizacion
    {
        $this->tenant->assertInTenant($id);
        $org = Organizacion::query()->with(['tipo:id,nombre', 'padre'])->find($id);
        if (! $org) {
            throw ValidationException::withMessages([
                'organizacion_id' => ['No se encontró esa organización.'],
            ]);
        }

        return $org;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function pathTo(Organizacion $current, Organizacion $root): array
    {
        $nodes = [];
        $cursor = $current;
        while ($cursor) {
            array_unshift($nodes, $this->payload($cursor));
            if ((int) $cursor->id === (int) $root->id) {
                $above = $cursor->padre;
                while ($above) {
                    array_unshift($nodes, $this->payload($above));
                    $above = $above->padre;
                }
                break;
            }
            $cursor = $cursor->padre;
        }

        return $nodes;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function childrenOf(Organizacion $parent): array
    {
        $allowed = $this->tenant->allowedOrganizationIds() ?? [];

        return Organizacion::query()
            ->with('tipo:id,nombre')
            ->where('organizacion_padre_id', $parent->id)
            ->where('estado', true)
            ->where(function ($query): void {
                $query->whereNull('estado_aprobacion')
                    ->orWhere('estado_aprobacion', '!=', Organizacion::APROBACION_RECHAZADA);
            })
            ->when($allowed !== [], fn ($query) => $query->whereIn('id', $allowed))
            ->orderBy('nombre')
            ->get()
            ->map(fn (Organizacion $org) => $this->payload($org))
            ->values()
            ->all();
    }

    /**
     * @return array<string, mixed>
     */
    private function payload(Organizacion $org): array
    {
        return [
            'id' => (int) $org->id,
            'nombre' => $org->nombre,
            'tipo_organizacion_id' => (int) $org->tipo_organizacion_id,
            'tipo_nombre' => $org->tipo?->nombre ?: $this->fallbackTipo((int) $org->tipo_organizacion_id),
            'is_club' => $org->isClubTipo(),
        ];
    }

    private function fallbackTipo(int $tipoId): string
    {
        return match ($tipoId) {
            Organizacion::TIPO_UNION => 'Unión',
            Organizacion::TIPO_ASOCIACION => 'Asociación',
            Organizacion::TIPO_ZONA => 'Zona',
            Organizacion::TIPO_DISTRITO => 'Distrito',
            Organizacion::TIPO_IGLESIA => 'Iglesia',
            Organizacion::TIPO_CLUB => 'Club',
            default => 'Organización',
        };
    }
}
