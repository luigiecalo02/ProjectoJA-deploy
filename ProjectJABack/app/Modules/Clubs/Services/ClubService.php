<?php

namespace App\Modules\Clubs\Services;

use App\Models\User;
use App\Modules\Clubs\Models\Club;
use App\Modules\Clubs\Models\Persona;
use App\Modules\Organizations\Models\Organizacion;
use App\Modules\Organizations\Models\PersonaOrganizacion;
use App\Modules\Organizations\Models\PersonaOrganizacionRol;
use App\Modules\Organizations\Services\OrganizacionRealtimeNotifier;
use App\Modules\Organizations\Services\OrganizationAccessService;
use App\Modules\Shared\Models\StoredFile;
use App\Modules\Shared\Services\AuditLogger;
use App\Modules\Users\Models\Role;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

final class ClubService
{
    public function __construct(
        private readonly AuditLogger $auditLogger,
        private readonly PersonaService $personaService,
        private readonly OrganizationAccessService $orgAccess,
        private readonly OrganizacionRealtimeNotifier $orgRealtime,
    ) {}

    public function list(User $actor, array $filters = [], int $perPage = 15): LengthAwarePaginator
    {
        $personasCountQuery = PersonaOrganizacion::query()
            ->selectRaw('count(*)')
            ->whereColumn('persona_organizacion.organizacion_id', 'clubes.organizacion_id')
            ->where('persona_organizacion.estado', true);

        $query = Club::query()
            ->select('clubes.*')
            ->selectSub($personasCountQuery, 'personas_count')
            ->with([
                'users:id,name,email',
                'organizacion:id,nombre,codigo,tipo_organizacion_id,organizacion_padre_id',
                'organizacion.padre:id,nombre,codigo,tipo_organizacion_id',
            ]);

        if ($this->orgAccess->shouldScopeByOrganization($actor)) {
            $orgIds = $this->orgAccess->accessibleOrganizationIds($actor);
            if ($orgIds === []) {
                $query->whereRaw('1 = 0');
            } else {
                $query->whereIn('organizacion_id', $orgIds);
            }
        }

        if (! empty($filters['q'])) {
            $q = trim((string) $filters['q']);
            $query->where(function ($inner) use ($q) {
                $inner->where('nombre', 'like', "%{$q}%")
                    ->orWhere('nombre_corto', 'like', "%{$q}%")
                    ->orWhere('distrito', 'like', "%{$q}%")
                    ->orWhere('ciudad', 'like', "%{$q}%");
            });
        }

        if (array_key_exists('is_active', $filters) && $filters['is_active'] !== null && $filters['is_active'] !== '') {
            $query->where('is_active', filter_var($filters['is_active'], FILTER_VALIDATE_BOOLEAN));
        }

        return $query->orderBy('nombre')->paginate($perPage);
    }

    public function userOwnsClub(User $actor, Club $club): bool
    {
        return $this->orgAccess->canAccessClub($actor, $club);
    }

    public function shouldScopeToOwnedClubs(User $actor): bool
    {
        return $this->orgAccess->shouldScopeByOrganization($actor);
    }

    /**
     * Clubes sin cuenta asociada, o ya asociados al usuario indicado (para editar).
     *
     * @return list<Club>
     */
    public function availableForAccount(?int $userId = null): array
    {
        return Club::query()
            ->with(['users:id,name,email'])
            ->where(function ($query) use ($userId) {
                $query->whereDoesntHave('users');
                if ($userId) {
                    $query->orWhereHas('users', fn ($q) => $q->where('users.id', $userId));
                }
            })
            ->orderBy('nombre')
            ->get()
            ->all();
    }

    public function find(int $id): Club
    {
        return Club::query()
            ->with([
                'organizacion:id,nombre,codigo,tipo_organizacion_id,organizacion_padre_id',
                'organizacion.padre:id,nombre,codigo,tipo_organizacion_id',
                'users:id,name,email',
            ])
            ->findOrFail($id);
    }

    public function create(User $actor, array $data): Club
    {
        return DB::transaction(function () use ($actor, $data) {
            $personaIds = $data['persona_ids'] ?? [];
            unset($data['persona_ids']);

            $iglesiaId = (int) ($data['iglesia_organizacion_id'] ?? $data['organizacion_id'] ?? 0);
            unset($data['iglesia_organizacion_id']);

            if ($iglesiaId <= 0) {
                throw ValidationException::withMessages([
                    'organizacion_id' => ['Debes asociar el club a una iglesia.'],
                ]);
            }

            if ($this->orgAccess->shouldScopeByOrganization($actor)
                && ! $this->orgAccess->canAccessOrganization($actor, $iglesiaId)) {
                throw ValidationException::withMessages([
                    'organizacion_id' => ['No tienes acceso a esa organización.'],
                ]);
            }

            $data['organizacion_id'] = $this->resolveClubOrganizacionId(
                $iglesiaId,
                (string) ($data['nombre'] ?? 'Club'),
            );

            $location = $this->locationLabelsFromIglesia($iglesiaId);
            $data['distrito'] = $location['distrito'];
            $data['ciudad'] = $location['ciudad'];

            $data['created_by'] = $actor->id;
            $data['is_active'] = $data['is_active'] ?? true;

            $club = Club::query()->create($data);
            if ($personaIds) {
                $this->syncMembersViaOrganizacion($club, $personaIds);
            }

            $club = $this->find($club->id);
            $this->auditLogger->log('clubs', 'create', null, $club->toArray(), $club);

            return $club;
        });
    }

    /**
     * Opciones de iglesia para crear/editar club.
     *
     * @return list<array<string, mixed>>
     */
    public function iglesiaOptions(User $actor): array
    {
        $query = Organizacion::query()
            ->with([
                'tipo:id,nombre',
                'padre:id,nombre,tipo_organizacion_id',
                'departamento:id,nombre',
                'ciudad:id,nombre',
            ])
            ->where('tipo_organizacion_id', Organizacion::TIPO_IGLESIA)
            ->where('estado', true)
            ->orderBy('nombre');

        if ($this->orgAccess->shouldScopeByOrganization($actor)) {
            $orgIds = $this->orgAccess->accessibleOrganizationIds($actor);
            if ($orgIds === []) {
                return [];
            }
            $query->whereIn('id', $orgIds);
        }

        return $query
            ->get([
                'id',
                'nombre',
                'codigo',
                'tipo_organizacion_id',
                'organizacion_padre_id',
                'departamento_id',
                'ciudad_id',
            ])
            ->map(fn (Organizacion $org) => $this->mapIglesiaOption($org))
            ->values()
            ->all();
    }

    /**
     * @return array<string, mixed>
     */
    private function mapIglesiaOption(Organizacion $org): array
    {
        $distrito = $org->padre?->nombre
            ?: $org->departamento?->nombre;

        return [
            'id' => $org->id,
            'nombre' => $org->nombre,
            'codigo' => $org->codigo,
            'tipo_organizacion_id' => $org->tipo_organizacion_id,
            'tipo_nombre' => $org->tipo?->nombre,
            'organizacion_padre_id' => $org->organizacion_padre_id,
            'distrito' => $distrito,
            'ciudad' => $org->ciudad?->nombre,
        ];
    }

    /**
     * @return array{distrito: string|null, ciudad: string|null}
     */
    private function locationLabelsFromIglesia(int $iglesiaId): array
    {
        $iglesia = Organizacion::query()
            ->with(['padre:id,nombre', 'departamento:id,nombre', 'ciudad:id,nombre'])
            ->find($iglesiaId);

        if (! $iglesia) {
            return ['distrito' => null, 'ciudad' => null];
        }

        return [
            'distrito' => $iglesia->padre?->nombre ?: $iglesia->departamento?->nombre,
            'ciudad' => $iglesia->ciudad?->nombre,
        ];
    }

    /**
     * Asocia (o reasocia) el club a una iglesia: mueve la org tipo Club y sincroniza ubicación.
     */
    private function syncClubIglesia(Club $club, int $iglesiaId, ?string $clubNombre = null): void
    {
        $iglesia = Organizacion::query()->find($iglesiaId);
        if (! $iglesia || (int) $iglesia->tipo_organizacion_id !== Organizacion::TIPO_IGLESIA) {
            throw ValidationException::withMessages([
                'organizacion_id' => ['Debes seleccionar una organización de tipo Iglesia.'],
            ]);
        }

        $nombre = $clubNombre ?: $club->nombre;

        if ($club->organizacion_id) {
            if (! $iglesia->pais_id || ! $iglesia->departamento_id || ! $iglesia->ciudad_id) {
                throw ValidationException::withMessages([
                    'organizacion_id' => ['La iglesia seleccionada debe tener país, departamento y ciudad definidos.'],
                ]);
            }

            Organizacion::query()->where('id', $club->organizacion_id)->update([
                'organizacion_padre_id' => $iglesiaId,
                'nombre' => $nombre,
                'pais_id' => $iglesia->pais_id,
                'departamento_id' => $iglesia->departamento_id,
                'ciudad_id' => $iglesia->ciudad_id,
                'direccion' => $iglesia->direccion,
            ]);
            $this->orgRealtime->notify('updated', (int) $club->organizacion_id);
        } else {
            $club->organizacion_id = $this->resolveClubOrganizacionId($iglesiaId, (string) $nombre);
        }

        $location = $this->locationLabelsFromIglesia($iglesiaId);
        $club->distrito = $location['distrito'];
        $club->ciudad = $location['ciudad'];
    }

    /**
     * Resuelve/crea la organización tipo Club bajo la iglesia seleccionada.
     */
    private function resolveClubOrganizacionId(int $iglesiaId, string $clubNombre): int
    {
        $iglesia = Organizacion::query()->find($iglesiaId);
        if (! $iglesia || (int) $iglesia->tipo_organizacion_id !== Organizacion::TIPO_IGLESIA) {
            throw ValidationException::withMessages([
                'organizacion_id' => ['Debes seleccionar una organización de tipo Iglesia.'],
            ]);
        }

        $existingClubOrgIds = Organizacion::query()
            ->where('organizacion_padre_id', $iglesiaId)
            ->where('tipo_organizacion_id', Organizacion::TIPO_CLUB)
            ->pluck('id');

        $freeOrgId = $existingClubOrgIds === []
            ? null
            : Club::query()
                ->whereIn('organizacion_id', $existingClubOrgIds)
                ->pluck('organizacion_id')
                ->pipe(function ($used) use ($existingClubOrgIds) {
                    return $existingClubOrgIds->diff($used)->first();
                });

        if ($freeOrgId) {
            Organizacion::query()->where('id', $freeOrgId)->update([
                'nombre' => $clubNombre,
            ]);
            $this->orgRealtime->notify('updated', (int) $freeOrgId);

            return (int) $freeOrgId;
        }

        if (! $iglesia->pais_id || ! $iglesia->departamento_id || ! $iglesia->ciudad_id) {
            throw ValidationException::withMessages([
                'organizacion_id' => ['La iglesia seleccionada debe tener país, departamento y ciudad definidos.'],
            ]);
        }

        $prefix = 'CLB';
        $seq = Organizacion::query()
            ->where('tipo_organizacion_id', Organizacion::TIPO_CLUB)
            ->count() + 1;
        $codigo = sprintf('%s-%04d', $prefix, $seq);

        $org = Organizacion::query()->create([
            'organizacion_padre_id' => $iglesiaId,
            'tipo_organizacion_id' => Organizacion::TIPO_CLUB,
            'nombre' => $clubNombre,
            'codigo' => $codigo,
            'pais_id' => $iglesia->pais_id,
            'departamento_id' => $iglesia->departamento_id,
            'ciudad_id' => $iglesia->ciudad_id,
            'direccion' => $iglesia->direccion,
            'estado' => true,
        ]);

        $this->orgRealtime->notify('created', (int) $org->id);

        return (int) $org->id;
    }

    /**
     * Sincroniza los integrantes del club como PersonaOrganizacion activas de su
     * organización tipo Club: crea/reactiva las de la lista y desactiva (sin borrar)
     * las que ya no correspondan, sin tocar roles de la persona en otras organizaciones.
     *
     * @param  list<int>  $personaIds
     */
    private function syncMembersViaOrganizacion(Club $club, array $personaIds): void
    {
        if (! $club->organizacion_id) {
            return;
        }

        $organizacionId = (int) $club->organizacion_id;
        $personaIds = array_values(array_unique(array_map('intval', $personaIds)));

        foreach ($personaIds as $personaId) {
            $this->ensurePersonaOrganizacion($club, $personaId);
        }

        PersonaOrganizacion::query()
            ->where('organizacion_id', $organizacionId)
            ->where('estado', true)
            ->whereNotIn('persona_id', $personaIds)
            ->update([
                'estado' => false,
                'fecha_fin' => now()->toDateString(),
            ]);
    }

    /**
     * Crea o reactiva la PersonaOrganizacion activa de la persona en la organización
     * tipo Club del club indicado.
     */
    private function ensurePersonaOrganizacion(Club $club, int $personaId): void
    {
        if (! $club->organizacion_id) {
            return;
        }

        $personaOrg = PersonaOrganizacion::query()->firstOrCreate(
            [
                'persona_id' => $personaId,
                'organizacion_id' => (int) $club->organizacion_id,
            ],
            [
                'fecha_inicio' => now()->toDateString(),
                'estado' => true,
            ]
        );

        if (! $personaOrg->estado) {
            $personaOrg->update(['estado' => true, 'fecha_fin' => null]);
        }
    }

    public function update(Club $club, array $data, User $actor): Club
    {
        return DB::transaction(function () use ($club, $data, $actor) {
            $old = $club->toArray();
            $personaIds = $data['persona_ids'] ?? null;
            $iglesiaId = null;
            if (array_key_exists('iglesia_organizacion_id', $data) || array_key_exists('organizacion_id', $data)) {
                $raw = $data['iglesia_organizacion_id'] ?? $data['organizacion_id'] ?? null;
                $iglesiaId = $raw !== null ? (int) $raw : null;
            }
            unset($data['persona_ids'], $data['organizacion_id'], $data['iglesia_organizacion_id'], $data['distrito'], $data['ciudad']);

            if ($iglesiaId) {
                if ($this->orgAccess->shouldScopeByOrganization($actor)
                    && ! $this->orgAccess->canAccessOrganization($actor, $iglesiaId)) {
                    throw ValidationException::withMessages([
                        'organizacion_id' => ['No tienes acceso a esa organización.'],
                    ]);
                }

                $this->syncClubIglesia(
                    $club,
                    $iglesiaId,
                    isset($data['nombre']) ? (string) $data['nombre'] : null,
                );
            } elseif ($club->organizacion_id) {
                // Sin cambio de iglesia: refrescar distrito/ciudad desde la iglesia actual.
                $currentIglesiaId = (int) (Organizacion::query()
                    ->where('id', $club->organizacion_id)
                    ->value('organizacion_padre_id') ?? 0);
                if ($currentIglesiaId > 0) {
                    $location = $this->locationLabelsFromIglesia($currentIglesiaId);
                    $club->distrito = $location['distrito'];
                    $club->ciudad = $location['ciudad'];
                }
            }

            $club->fill($data);
            $club->save();

            if (is_array($personaIds)) {
                $this->syncMembersViaOrganizacion($club, $personaIds);
            }

            // Mantener el nombre de la organización tipo Club alineado al club.
            if ($club->organizacion_id && ! empty($data['nombre'])) {
                Organizacion::query()->where('id', $club->organizacion_id)->update([
                    'nombre' => (string) $data['nombre'],
                ]);
                $this->orgRealtime->notify('updated', (int) $club->organizacion_id);
            }

            $club = $this->find($club->id);
            $this->auditLogger->log('clubs', 'update', $old, $club->toArray(), $club);

            return $club;
        });
    }

    public function delete(Club $club): void
    {
        $old = $club->toArray();

        if ($club->organizacion_id) {
            PersonaOrganizacion::query()
                ->where('organizacion_id', $club->organizacion_id)
                ->where('estado', true)
                ->update([
                    'estado' => false,
                    'fecha_fin' => now()->toDateString(),
                ]);
        }

        $club->delete();
        $this->auditLogger->log('clubs', 'delete', $old, null, $club);
    }

    public function syncMembers(Club $club, array $personaIds): Club
    {
        $this->syncMembersViaOrganizacion($club, $personaIds);

        return $this->find($club->id);
    }

    public function addPersona(Club $club, array $personaData, User $actor, ?string $cargo = null): Club
    {
        return DB::transaction(function () use ($club, $personaData, $actor) {
            unset($personaData['club_ids']);
            if ($club->organizacion_id && empty($personaData['organizacion_ids'])) {
                $personaData['organizacion_ids'] = [(int) $club->organizacion_id];
            }
            $this->personaService->create($personaData, $actor);

            return $this->find($club->id);
        });
    }

    public function storeLogo(Club $club, UploadedFile $file, User $actor): Club
    {
        $directory = "clubs/{$club->id}";
        $storedPath = $file->store($directory, 'public');
        $url = url('storage/'.$storedPath);

        StoredFile::query()->create([
            'name' => $file->getClientOriginalName(),
            'path' => $storedPath,
            'size' => $file->getSize() ?: 0,
            'mime_type' => $file->getMimeType(),
            'hash' => hash_file('sha256', $file->getRealPath()) ?: null,
            'uploaded_by' => $actor->id,
        ]);

        $old = ['logo' => $club->logo];
        $club->update(['logo' => $url]);
        $this->auditLogger->log('clubs', 'logo', $old, ['logo' => $url], $club);

        return $this->find($club->id);
    }

    /**
     * @param  array<string, array<string, mixed>|null>  $directors
     */
    public function syncDirectors(Club $club, array $directors, User $actor): Club
    {
        return DB::transaction(function () use ($club, $directors) {
            foreach (Club::BOARD_POSITIONS as $position) {
                if (! array_key_exists($position, $directors)) {
                    continue;
                }

                $roleName = Club::roleForBoardPosition($position, $club->tipos);
                $payload = $directors[$position];
                $current = $this->currentBoardHolder($club, $roleName);

                if ($payload === null || ($payload['clear'] ?? false) === true) {
                    if ($current !== null && $current['user_id'] !== null) {
                        $this->detachBoardRole($current['user_id'], $club, $roleName);
                    }

                    continue;
                }

                $userId = $this->resolveDirectorUser($club, $position, $payload, $roleName);

                if ($current !== null && $current['user_id'] !== null && $current['user_id'] !== $userId) {
                    $this->detachBoardRole($current['user_id'], $club, $roleName);
                }

                $this->ensurePersonaForBoardUser($club, $userId, $position, $payload);

                $roleId = Role::query()->where('name', $roleName)->value('id');
                $user = User::query()->find($userId);
                if ($user && $roleId) {
                    $this->attachPersonaOrgRole($user, $club, (int) $roleId);
                }

                // Un rol de directiva solo lo puede tener una persona por organización.
                $this->detachOtherRoleHoldersInOrganization($club, $roleName, $userId);
            }

            $club = $this->find($club->id);
            $this->auditLogger->log('clubs', 'directors', null, [
                'directors' => $this->boardAssignments($club),
            ], $club);

            return $club;
        });
    }

    /**
     * Persona/usuario que actualmente ocupa un cargo de directiva (rol) en la
     * organización tipo Club del club indicado.
     *
     * @return array{persona_id: int, user_id: int|null}|null
     */
    private function currentBoardHolder(Club $club, string $roleName): ?array
    {
        if (! $club->organizacion_id) {
            return null;
        }

        $roleId = Role::query()->where('name', $roleName)->value('id');
        if (! $roleId) {
            return null;
        }

        $row = PersonaOrganizacionRol::query()
            ->join('persona_organizacion', 'persona_organizacion.id', '=', 'persona_organizacion_rol.persona_organizacion_id')
            ->where('persona_organizacion.organizacion_id', $club->organizacion_id)
            ->where('persona_organizacion.estado', true)
            ->where('persona_organizacion_rol.rol_id', $roleId)
            ->select(['persona_organizacion.persona_id as persona_id'])
            ->first();

        if (! $row) {
            return null;
        }

        $userId = User::query()->where('persona_id', $row->persona_id)->value('id');

        return [
            'persona_id' => (int) $row->persona_id,
            'user_id' => $userId ? (int) $userId : null,
        ];
    }

    /**
     * Quita el rol de directiva a cualquier otra persona que lo tenga en la misma
     * organización del club (un cargo solo lo puede ocupar una persona a la vez).
     */
    private function detachOtherRoleHoldersInOrganization(Club $club, string $roleName, int $keepUserId): void
    {
        if (! $club->organizacion_id) {
            return;
        }

        $roleId = Role::query()->where('name', $roleName)->value('id');
        if (! $roleId) {
            return;
        }

        $rows = PersonaOrganizacionRol::query()
            ->join('persona_organizacion', 'persona_organizacion.id', '=', 'persona_organizacion_rol.persona_organizacion_id')
            ->where('persona_organizacion.organizacion_id', $club->organizacion_id)
            ->where('persona_organizacion_rol.rol_id', $roleId)
            ->select(['persona_organizacion.persona_id as persona_id'])
            ->get();

        foreach ($rows as $row) {
            $otherUserId = User::query()->where('persona_id', $row->persona_id)->value('id');
            if (! $otherUserId || (int) $otherUserId === $keepUserId) {
                continue;
            }

            $this->detachBoardRole((int) $otherUserId, $club, $roleName);
        }
    }

    private function detachBoardRole(int $userId, Club $club, string $roleName): void
    {
        $user = User::query()->find($userId);
        if (! $user || ! $user->persona_id) {
            return;
        }

        $roleId = Role::query()->where('name', $roleName)->value('id');
        if (! $roleId) {
            return;
        }

        // Al reemplazar/limpiar, solo quitar el rol de la persona si no le queda
        // ninguna otra organización tipo Club activa donde aún tenga ese mismo rol
        // (la asignación actual, que todavía no se ha quitado, cuenta como una).
        $otherAssignments = PersonaOrganizacionRol::query()
            ->join('persona_organizacion', 'persona_organizacion.id', '=', 'persona_organizacion_rol.persona_organizacion_id')
            ->join('clubes', 'clubes.organizacion_id', '=', 'persona_organizacion.organizacion_id')
            ->where('persona_organizacion.persona_id', $user->persona_id)
            ->where('persona_organizacion.estado', true)
            ->where('persona_organizacion_rol.rol_id', $roleId)
            ->count();

        if ($otherAssignments <= 1) {
            $this->detachPersonaOrgRole($user, $club, (int) $roleId);
        }
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function resolveDirectorUser(Club $club, string $position, array $payload, string $roleName): int
    {
        $roleId = Role::query()->where('name', $roleName)->value('id');
        if (! $roleId) {
            throw ValidationException::withMessages([
                'directors' => ["No existe el rol {$roleName}."],
            ]);
        }

        $mode = $payload['mode'] ?? 'select';

        if ($mode === 'select' && ! empty($payload['persona_id'])) {
            return $this->resolveUserFromPersona((int) $payload['persona_id'], $position, $roleId, $payload, $club);
        }

        if ($mode === 'create') {
            $userData = $payload['user'] ?? [];
            $email = isset($userData['email']) ? Str::lower(trim((string) $userData['email'])) : null;
            if (! $email || empty($userData['password'])) {
                throw ValidationException::withMessages([
                    "directors.{$position}" => ['Identificación, nombres, correo y contraseña son obligatorios para crear el cargo.'],
                ]);
            }

            if (empty($userData['name'])) {
                $userData['name'] = trim(collect([
                    $payload['persona']['nombre1'] ?? null,
                    $payload['persona']['nombre2'] ?? null,
                    $payload['persona']['apellido1'] ?? null,
                    $payload['persona']['apellido2'] ?? null,
                ])->filter()->implode(' '));
            }

            if ($userData['name'] === '') {
                throw ValidationException::withMessages([
                    "directors.{$position}" => ['Primer nombre y primer apellido son obligatorios.'],
                ]);
            }

            $existing = User::query()->where('email', $email)->first();
            if ($existing) {
                throw ValidationException::withMessages([
                    "directors.{$position}.user.email" => ['Este correo ya está registrado. Usa “Seleccionar” o elige otro correo.'],
                ]);
            }

            $user = User::query()->create([
                'name' => $userData['name'],
                'email' => $email,
                'password' => $userData['password'],
                'is_active' => true,
                'email_verified_at' => now(),
            ]);
            $this->attachPersonaOrgRole($user, $club, (int) $roleId);

            return $user->id;
        }

        $userId = (int) ($payload['user_id'] ?? 0);
        $user = User::query()->find($userId);
        if (! $user) {
            throw ValidationException::withMessages([
                "directors.{$position}" => ['Debes seleccionar una persona o usuario válido.'],
            ]);
        }

        $this->attachPersonaOrgRole($user, $club, (int) $roleId);

        return $user->id;
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function resolveUserFromPersona(int $personaId, string $position, int $roleId, array $payload, Club $club): int
    {
        $persona = Persona::query()->find($personaId);
        if (! $persona) {
            throw ValidationException::withMessages([
                "directors.{$position}.persona_id" => ['La persona seleccionada no existe.'],
            ]);
        }

        if ($persona->user) {
            $user = $persona->user;
            $this->attachPersonaOrgRole($user, $club, $roleId);

            return $user->id;
        }

        $email = filled($persona->correo)
            ? Str::lower(trim((string) $persona->correo))
            : (isset($payload['user']['email']) ? Str::lower(trim((string) $payload['user']['email'])) : null);

        if (! $email) {
            throw ValidationException::withMessages([
                "directors.{$position}.persona_id" => ['La persona no tiene correo. Agrégalo o indícalo al asignar el cargo.'],
            ]);
        }

        if (! filled($persona->correo)) {
            $persona->update(['correo' => $email]);
        }

        $existingByEmail = User::query()->where('email', $email)->first();
        if ($existingByEmail) {
            if ($existingByEmail->persona_id && (int) $existingByEmail->persona_id !== (int) $persona->id) {
                throw ValidationException::withMessages([
                    "directors.{$position}.persona_id" => ['El correo de esta persona ya pertenece a un usuario vinculado a otra persona.'],
                ]);
            }
            $existingByEmail->update(['persona_id' => $persona->id]);
            $persona->update(['correo' => $email]);
            $this->attachPersonaOrgRole($existingByEmail, $club, $roleId);

            return $existingByEmail->id;
        }

        $password = $payload['user']['password'] ?? null;
        if (! filled($password)) {
            $password = $persona->identificacion.'Aa1!';
        }

        $user = User::query()->create([
            'persona_id' => $persona->id,
            'name' => $persona->full_name,
            'email' => $email,
            'password' => $password,
            'is_active' => true,
            'email_verified_at' => now(),
        ]);
        $this->attachPersonaOrgRole($user, $club, $roleId);
        $persona->update(['correo' => $email]);

        return $user->id;
    }

    private function attachPersonaOrgRole(User $user, Club $club, int $roleId): void
    {
        $user->refresh();
        if (! $user->persona_id || ! $club->organizacion_id) {
            $user->clearPermissionCache();

            return;
        }

        $personaOrg = PersonaOrganizacion::query()->firstOrCreate(
            [
                'persona_id' => $user->persona_id,
                'organizacion_id' => $club->organizacion_id,
            ],
            [
                'fecha_inicio' => now()->toDateString(),
                'estado' => true,
            ]
        );

        PersonaOrganizacionRol::query()->firstOrCreate(
            [
                'persona_organizacion_id' => $personaOrg->id,
                'rol_id' => $roleId,
            ],
            [
                'created_at' => now(),
            ]
        );

        $user->clearPermissionCache();
    }

    private function detachPersonaOrgRole(User $user, Club $club, int $roleId): void
    {
        if (! $user->persona_id || ! $club->organizacion_id) {
            $user->clearPermissionCache();

            return;
        }

        $personaOrg = PersonaOrganizacion::query()
            ->where('persona_id', $user->persona_id)
            ->where('organizacion_id', $club->organizacion_id)
            ->first();

        if ($personaOrg) {
            PersonaOrganizacionRol::query()
                ->where('persona_organizacion_id', $personaOrg->id)
                ->where('rol_id', $roleId)
                ->delete();
        }

        $user->clearPermissionCache();
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function ensurePersonaForBoardUser(Club $club, int $userId, string $position, array $payload): void
    {
        $user = User::query()->findOrFail($userId);
        $personaData = is_array($payload['persona'] ?? null) ? $payload['persona'] : [];

        if (! empty($payload['persona_id'])) {
            $persona = Persona::query()->find((int) $payload['persona_id']);
            if ($persona) {
                $linkedUserId = $persona->user()?->value('users.id');
                if ($linkedUserId && (int) $linkedUserId !== $userId) {
                    throw ValidationException::withMessages([
                        "directors.{$position}" => ['Esta persona ya está vinculada a otro usuario.'],
                    ]);
                }
                $user->update(['persona_id' => $persona->id]);
                $persona->update([
                    'correo' => $persona->correo ?: $user->email,
                ]);
                $this->ensurePersonaOrganizacion($club, $persona->id);

                return;
            }
        }

        $persona = $user->persona;

        if (! $persona) {
            $persona = Persona::query()
                ->whereNotNull('correo')
                ->whereRaw('LOWER(correo) = ?', [Str::lower($user->email)])
                ->whereDoesntHave('user')
                ->first();
        }

        if (! $persona) {
            $parts = preg_split('/\s+/', trim($user->name), 2) ?: [$user->name];
            $nombre1 = filled($personaData['nombre1'] ?? null) ? (string) $personaData['nombre1'] : ($parts[0] ?? $user->name);
            $apellido1 = filled($personaData['apellido1'] ?? null)
                ? (string) $personaData['apellido1']
                : ($parts[1] ?? 'N/A');

            $identificacion = filled($personaData['identificacion'] ?? null)
                ? (string) $personaData['identificacion']
                : 'USR-'.$userId;

            if (Persona::query()->where('identificacion', $identificacion)->exists()) {
                throw ValidationException::withMessages([
                    "directors.{$position}.persona.identificacion" => ['Ya existe una persona con este número de identificación.'],
                ]);
            }

            $persona = Persona::query()->create([
                'tipo_identificacion' => $personaData['tipo_identificacion'] ?? 'CC',
                'identificacion' => $identificacion,
                'nombre1' => $nombre1,
                'nombre2' => $personaData['nombre2'] ?? null,
                'apellido1' => $apellido1,
                'apellido2' => $personaData['apellido2'] ?? null,
                'telefono' => $personaData['telefono'] ?? null,
                'correo' => $user->email,
                'sexo' => $personaData['sexo'] ?? null,
                'fecha_nacimiento' => $personaData['fecha_nacimiento'] ?? null,
                'direccion_actual' => $personaData['direccion_actual'] ?? null,
            ]);
            $user->update(['persona_id' => $persona->id]);
        } else {
            $linkedUserId = $persona->user()?->value('users.id');
            if ($linkedUserId && (int) $linkedUserId !== $userId) {
                throw ValidationException::withMessages([
                    "directors.{$position}" => ['Esta persona ya está vinculada a otro usuario.'],
                ]);
            }

            $user->update(['persona_id' => $persona->id]);
            $persona->update(array_filter([
                'correo' => $persona->correo ?: $user->email,
                'nombre1' => $personaData['nombre1'] ?? null,
                'nombre2' => $personaData['nombre2'] ?? null,
                'apellido1' => $personaData['apellido1'] ?? null,
                'apellido2' => $personaData['apellido2'] ?? null,
                'telefono' => $personaData['telefono'] ?? null,
                'tipo_identificacion' => $personaData['tipo_identificacion'] ?? null,
                'identificacion' => $personaData['identificacion'] ?? null,
            ], fn ($value) => $value !== null && $value !== ''));
        }

        $this->ensurePersonaOrganizacion($club, $persona->id);
    }

    /**
     * @param  list<string>|null  $tipos
     * @return list<User>
     */
    public function directorsCatalog(string $position, ?array $tipos = null, ?int $clubId = null): array
    {
        try {
            $roleName = Club::roleForBoardPosition($position, $tipos);
        } catch (\InvalidArgumentException) {
            return [];
        }

        $organizacionId = $clubId
            ? Club::query()->whereKey($clubId)->value('organizacion_id')
            : null;

        return User::query()
            ->where('is_active', true)
            ->where(function ($query) use ($roleName, $organizacionId) {
                $query->whereExists(function ($sub) use ($roleName, $organizacionId) {
                    $sub->selectRaw('1')
                        ->from('persona_organizacion_rol')
                        ->join(
                            'persona_organizacion',
                            'persona_organizacion.id',
                            '=',
                            'persona_organizacion_rol.persona_organizacion_id'
                        )
                        ->join('roles', 'roles.id', '=', 'persona_organizacion_rol.rol_id')
                        ->whereColumn('persona_organizacion.persona_id', 'users.persona_id')
                        ->where('persona_organizacion.estado', true)
                        ->where('roles.name', $roleName);

                    if ($organizacionId) {
                        $sub->where('persona_organizacion.organizacion_id', $organizacionId);
                    }
                });
            })
            ->orderBy('name')
            ->get(['id', 'name', 'email'])
            ->all();
    }

    /**
     * Integrantes activos del club (PersonaOrganizacion activas de su organización).
     *
     * @return Collection<int, Persona>
     */
    public function memberPersonas(Club $club): Collection
    {
        if (! $club->organizacion_id) {
            return collect();
        }

        $personaIds = PersonaOrganizacion::query()
            ->where('organizacion_id', $club->organizacion_id)
            ->where('estado', true)
            ->pluck('persona_id');

        return Persona::query()
            ->with('user:id,persona_id,name,email')
            ->whereIn('id', $personaIds)
            ->orderBy('apellido1')
            ->orderBy('nombre1')
            ->get();
    }

    /**
     * Directiva actual del club, construida desde PersonaOrganizacionRol.
     *
     * @return list<array<string, mixed>>
     */
    public function boardAssignments(Club $club): array
    {
        if (! $club->organizacion_id) {
            return [];
        }

        $roleNames = Club::boardRoleNames();
        if ($roleNames === []) {
            return [];
        }

        $rows = PersonaOrganizacionRol::query()
            ->join('persona_organizacion', 'persona_organizacion.id', '=', 'persona_organizacion_rol.persona_organizacion_id')
            ->join('roles', 'roles.id', '=', 'persona_organizacion_rol.rol_id')
            ->where('persona_organizacion.organizacion_id', $club->organizacion_id)
            ->where('persona_organizacion.estado', true)
            ->whereIn('roles.name', $roleNames)
            ->select(['roles.name as role_name', 'persona_organizacion.persona_id as persona_id'])
            ->get();

        $assignments = [];
        foreach ($rows as $row) {
            $position = Club::positionForRoleName($row->role_name);
            if (! $position) {
                continue;
            }

            $persona = Persona::query()->with('user')->find($row->persona_id);
            $user = $persona?->user;

            $assignments[] = [
                'ministry' => $position,
                'user_id' => $user?->id,
                'persona_id' => $persona?->id,
                'user' => $user ? [
                    'id' => $user->id,
                    'name' => $user->name,
                    'email' => $user->email,
                ] : null,
                'persona' => $persona ? [
                    'id' => $persona->id,
                    'user_id' => $user?->id,
                    'tipo_identificacion' => $persona->tipo_identificacion,
                    'identificacion' => $persona->identificacion,
                    'nombre1' => $persona->nombre1,
                    'apellido1' => $persona->apellido1,
                    'correo' => $persona->correo,
                    'full_name' => $persona->full_name,
                ] : null,
            ];
        }

        return $assignments;
    }
}
