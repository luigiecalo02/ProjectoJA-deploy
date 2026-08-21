<?php

namespace App\Modules\Users\Services;

use App\Models\User;
use App\Modules\Auth\Services\AccountMailService;
use App\Modules\Clubs\Models\Club;
use App\Modules\Clubs\Models\Persona;
use App\Modules\Organizations\Models\Organizacion;
use App\Modules\Organizations\Models\PersonaOrganizacion;
use App\Modules\Organizations\Models\PersonaOrganizacionRol;
use App\Modules\Organizations\Services\OrganizationAccessService;
use App\Modules\Settings\Services\MailSettingsService;
use App\Modules\Shared\Models\StoredFile;
use App\Modules\Shared\Services\AuditLogger;
use App\Modules\Shared\Services\ImageOptimizer;
use App\Modules\Users\Models\Role;
use App\Modules\Users\Repositories\UserRepository;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class UserService
{
    public function __construct(
        private readonly UserRepository $users,
        private readonly AuditLogger $auditLogger,
        private readonly ImageOptimizer $imageOptimizer,
        private readonly AccountMailService $accountMail,
        private readonly MailSettingsService $mailSettings,
        private readonly OrganizationAccessService $orgAccess,
    ) {}

    public function list(array $filters = [], int $perPage = 15, ?User $actor = null): LengthAwarePaginator
    {
        if ($actor) {
            $filters = $this->constrainOrganizationFilter($actor, $filters);
        }

        return $this->users->paginate($filters, $perPage);
    }

    public function find(int $id): User
    {
        return $this->users->findOrFail($id);
    }

    public function create(array $data): User
    {
        $user = DB::transaction(function () use ($data) {
            $roleIds = $data['role_ids'] ?? [];
            $clubIds = $data['club_ids'] ?? null;
            $personaId = isset($data['persona_id']) ? (int) $data['persona_id'] : null;
            $personaData = is_array($data['persona'] ?? null) ? $data['persona'] : [];
            $organizaciones = $this->normalizeOrganizacionesPayload($data);
            unset(
                $data['role_ids'],
                $data['club_ids'],
                $data['persona_id'],
                $data['persona'],
                $data['organizacion_id'],
                $data['organizacion_rol_id'],
                $data['organizaciones'],
            );

            $data['is_active'] = $data['is_active'] ?? true;

            $user = User::query()->create($data);
            $this->applyPlatformFlags($user, is_array($roleIds) ? $roleIds : []);

            $this->syncClubsForUser($user->fresh(), $clubIds, is_array($roleIds) ? $roleIds : null);
            $persona = $this->attachPersonaToUser($user, $personaId, $personaData);

            if ($organizaciones !== []) {
                $this->assignPersonaOrganizaciones($persona, $organizaciones);
                $user->clearPermissionCache();
            }

            $user->load(['clubs', 'persona.organizaciones.organizacion', 'persona.organizaciones.rolesAsignados.rol']);
            $this->auditLogger->log('users', 'create', null, $user->toArray(), $user);

            return $user;
        });

        $this->sendWelcomeVerification($user);

        return $user->fresh(['clubs', 'persona.organizaciones.organizacion', 'persona.organizaciones.rolesAsignados.rol']) ?? $user;
    }

    private function sendWelcomeVerification(User $user): void
    {
        if ($user->email_verified_at) {
            return;
        }

        if (! $this->mailSettings->isConfigured()) {
            $user->forceFill(['email_verified_at' => now()])->save();

            return;
        }

        $this->accountMail->trySendVerification($user);
    }

    public function update(User $user, array $data): User
    {
        return DB::transaction(function () use ($user, $data) {
            $old = $user->toArray();
            $roleIds = $data['role_ids'] ?? null;
            $clubIds = array_key_exists('club_ids', $data) ? $data['club_ids'] : null;
            $personaId = array_key_exists('persona_id', $data)
                ? ($data['persona_id'] !== null ? (int) $data['persona_id'] : null)
                : null;
            $personaData = is_array($data['persona'] ?? null) ? $data['persona'] : null;
            $hasOrganizaciones = array_key_exists('organizaciones', $data)
                || array_key_exists('organizacion_id', $data);
            $organizaciones = $hasOrganizaciones ? $this->normalizeOrganizacionesPayload($data) : null;
            unset(
                $data['role_ids'],
                $data['club_ids'],
                $data['persona_id'],
                $data['persona'],
                $data['organizacion_id'],
                $data['organizacion_rol_id'],
                $data['organizaciones'],
            );

            if (array_key_exists('password', $data) && empty($data['password'])) {
                unset($data['password']);
            }

            $user->update($data);

            if (is_array($roleIds)) {
                $this->applyPlatformFlags($user, $roleIds);
            }

            if (is_array($clubIds) || $clubIds === []) {
                $this->syncClubsForUser($user->fresh(), $clubIds ?? [], is_array($roleIds) ? $roleIds : null);
            } elseif (is_array($roleIds)) {
                $this->syncClubsForUser($user->fresh(), null, $roleIds);
            }

            $user->loadMissing('persona');
            if (! $user->persona_id) {
                if ($personaId || (is_array($personaData) && $personaData !== [])) {
                    $persona = $this->attachPersonaToUser($user, $personaId, $personaData ?? []);
                } else {
                    throw ValidationException::withMessages([
                        'persona_id' => ['El usuario debe estar asociado a una persona.'],
                    ]);
                }
            } else {
                $persona = $user->persona;
            }

            if ($organizaciones !== null && $persona) {
                $this->assignPersonaOrganizaciones($persona, $organizaciones);
                $user->clearPermissionCache();
            }

            $user->load(['clubs', 'persona.organizaciones.organizacion', 'persona.organizaciones.rolesAsignados.rol']);
            $this->auditLogger->log('users', 'update', $old, $user->toArray(), $user);

            return $user->fresh(['clubs', 'persona.organizaciones.organizacion', 'persona.organizaciones.rolesAsignados.rol']);
        });
    }

    public function delete(User $user): void
    {
        $old = $user->toArray();
        $user->delete();
        $user->clearPermissionCache();
        $this->auditLogger->log('users', 'delete', $old, null, $user);
    }

    public function setStatus(User $user, bool $isActive): User
    {
        $old = ['is_active' => $user->is_active];
        $user->update(['is_active' => $isActive]);
        $this->auditLogger->log('users', 'status', $old, ['is_active' => $isActive], $user);

        return $user->fresh(['clubs', 'persona']);
    }

    public function syncRoles(User $user, array $roleIds): User
    {
        $old = $user->roleNames();
        $this->applyPlatformFlags($user, $roleIds);
        $this->syncClubsForUser($user->fresh(), $user->clubs()->pluck('clubes.id')->all(), $roleIds);
        $user->load(['clubs', 'persona']);
        $this->auditLogger->log('users', 'assign_roles', ['roles' => $old], ['roles' => $user->roleNames()], $user);

        return $user;
    }

    public function updateAvatar(User $user, array $fileMeta): User
    {
        $old = ['avatar_url' => $user->avatar_url];

        StoredFile::query()->create([
            'name' => $fileMeta['name'],
            'path' => $fileMeta['path'],
            'size' => $fileMeta['size'] ?? 0,
            'mime_type' => $fileMeta['mime_type'] ?? null,
            'hash' => $fileMeta['hash'] ?? null,
            'uploaded_by' => $user->id,
        ]);

        $user->update(['avatar_url' => $fileMeta['url'] ?? $fileMeta['path']]);
        $this->auditLogger->log(
            'users',
            'avatar',
            $old,
            ['avatar_url' => $fileMeta['url'] ?? $fileMeta['path']],
            $user
        );

        return $user->fresh(['persona']);
    }

    public function storeAvatarFile(User $user, UploadedFile $file): User
    {
        $stored = $this->imageOptimizer->store($file, "avatars/{$user->id}", 'avatar');

        return $this->updateAvatar($user, [
            'name' => $file->getClientOriginalName(),
            'path' => $stored->path,
            'url' => url('storage/'.$stored->path),
            'size' => $stored->size,
            'mime_type' => $stored->mime,
            'hash' => $stored->hash,
        ]);
    }

    /**
     * Crea personas stub y las vincula en users.persona_id.
     */
    public function linkOrphanUsers(): int
    {
        $created = 0;
        $orphans = User::query()
            ->whereNull('persona_id')
            ->orderBy('id')
            ->get();

        foreach ($orphans as $user) {
            DB::transaction(function () use ($user, &$created) {
                $parts = preg_split('/\s+/', trim((string) $user->name)) ?: [];
                $nombre1 = $parts[0] ?? 'Usuario';
                $apellido1 = $parts[1] ?? $nombre1;
                $identificacion = 'USR-'.$user->id;

                $persona = Persona::query()->create([
                    'tipo_identificacion' => 'CC',
                    'identificacion' => $identificacion,
                    'nombre1' => $nombre1,
                    'apellido1' => $apellido1,
                    'correo' => $user->email,
                ]);

                $user->update(['persona_id' => $persona->id]);
                $created++;
            });
        }

        return $created;
    }

    /**
     * @param  array<string, mixed>  $personaData
     */
    private function attachPersonaToUser(User $user, ?int $personaId, array $personaData): Persona
    {
        if ($personaId) {
            $persona = Persona::query()->find($personaId);
            if (! $persona) {
                throw ValidationException::withMessages([
                    'persona_id' => ['La persona seleccionada no existe.'],
                ]);
            }

            $taken = User::query()
                ->where('persona_id', $persona->id)
                ->where('id', '!=', $user->id)
                ->exists();

            if ($taken) {
                throw ValidationException::withMessages([
                    'persona_id' => ['Esta persona ya está asociada a otro usuario.'],
                ]);
            }

            if (! $persona->correo) {
                $persona->update(['correo' => $user->email]);
            }

            $user->update(['persona_id' => $persona->id]);

            return $persona->fresh();
        }

        $identificacion = trim((string) ($personaData['identificacion'] ?? ''));
        $nombre1 = trim((string) ($personaData['nombre1'] ?? ''));
        $apellido1 = trim((string) ($personaData['apellido1'] ?? ''));

        if ($identificacion === '' || $nombre1 === '' || $apellido1 === '') {
            throw ValidationException::withMessages([
                'persona.identificacion' => ['Debes asociar una persona existente o registrar una nueva (identificación, nombre y apellido).'],
            ]);
        }

        $exists = Persona::query()
            ->where('identificacion', $identificacion)
            ->whereNull('deleted_at')
            ->exists();

        if ($exists) {
            throw ValidationException::withMessages([
                'persona.identificacion' => ['Ya existe una persona con este número de identificación.'],
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
            'correo' => $personaData['correo'] ?? $user->email,
            'direccion_actual' => $personaData['direccion_actual'] ?? null,
            'fecha_nacimiento' => $personaData['fecha_nacimiento'] ?? null,
            'sexo' => $personaData['sexo'] ?? null,
        ]);

        $user->update(['persona_id' => $persona->id]);

        return $persona;
    }

    /**
     * @param  array<string, mixed>  $data
     * @return list<array{organizacion_id: int, rol_ids: list<int>, fecha_inicio?: string|null, fecha_fin?: string|null, estado?: bool}>
     */
    private function normalizeOrganizacionesPayload(array $data): array
    {
        if (is_array($data['organizaciones'] ?? null)) {
            $items = [];
            foreach ($data['organizaciones'] as $index => $row) {
                if (! is_array($row) || empty($row['organizacion_id'])) {
                    continue;
                }

                $rolIds = [];
                if (is_array($row['rol_ids'] ?? null)) {
                    $rolIds = array_values(array_unique(array_map('intval', $row['rol_ids'])));
                } elseif (! empty($row['rol_id'])) {
                    $rolIds = [(int) $row['rol_id']];
                }

                if ($rolIds === []) {
                    throw ValidationException::withMessages([
                        "organizaciones.{$index}.rol_ids" => ['Cada organización debe tener al menos un rol.'],
                    ]);
                }

                $items[] = [
                    'organizacion_id' => (int) $row['organizacion_id'],
                    'rol_ids' => $rolIds,
                    'fecha_inicio' => $row['fecha_inicio'] ?? null,
                    'fecha_fin' => $row['fecha_fin'] ?? null,
                    'estado' => array_key_exists('estado', $row) ? (bool) $row['estado'] : true,
                ];
            }

            $orgIds = array_column($items, 'organizacion_id');
            if (count($orgIds) !== count(array_unique($orgIds))) {
                throw ValidationException::withMessages([
                    'organizaciones' => ['No puedes repetir la misma organización.'],
                ]);
            }

            return $items;
        }

        // Compatibilidad: un solo par organizacion_id + organizacion_rol_id
        if (! empty($data['organizacion_id'])) {
            $rolId = isset($data['organizacion_rol_id']) ? (int) $data['organizacion_rol_id'] : null;
            if (! $rolId) {
                throw ValidationException::withMessages([
                    'organizacion_rol_id' => ['Si eliges organización, también debes elegir el rol.'],
                ]);
            }

            return [[
                'organizacion_id' => (int) $data['organizacion_id'],
                'rol_ids' => [$rolId],
                'estado' => true,
            ]];
        }

        return [];
    }

    /**
     * Sincroniza organizaciones/roles de la persona: crea/actualiza las enviadas
     * y desactiva las que ya no vienen en el payload (evita acumular registros).
     *
     * @param  list<array{organizacion_id: int, rol_ids: list<int>, fecha_inicio?: string|null, fecha_fin?: string|null, estado?: bool}>  $items
     */
    private function assignPersonaOrganizaciones(Persona $persona, array $items): void
    {
        $keepOrgIds = [];

        foreach ($items as $index => $item) {
            $orgId = (int) $item['organizacion_id'];
            $keepOrgIds[] = $orgId;
            $this->assignPersonaOrganizacion(
                $persona,
                $orgId,
                $item['rol_ids'],
                $item['fecha_inicio'] ?? null,
                $item['fecha_fin'] ?? null,
                $item['estado'] ?? true,
                $index,
            );
        }

        $keepOrgIds = array_values(array_unique($keepOrgIds));

        $staleQuery = PersonaOrganizacion::query()->where('persona_id', $persona->id);
        if ($keepOrgIds !== []) {
            $staleQuery->whereNotIn('organizacion_id', $keepOrgIds);
        }

        foreach ($staleQuery->get() as $personaOrg) {
            PersonaOrganizacionRol::query()
                ->where('persona_organizacion_id', $personaOrg->id)
                ->delete();
            $personaOrg->update([
                'estado' => false,
                'fecha_fin' => $personaOrg->fecha_fin?->format('Y-m-d') ?: now()->toDateString(),
            ]);
        }

        // Si el contexto activo apunta a una org que ya no está, limpiarlo.
        $user = User::query()->where('persona_id', $persona->id)->first();
        if ($user && $user->active_organizacion_id) {
            $stillActive = PersonaOrganizacion::query()
                ->where('persona_id', $persona->id)
                ->where('organizacion_id', $user->active_organizacion_id)
                ->where('estado', true)
                ->exists();
            if (! $stillActive) {
                $user->forceFill([
                    'active_organizacion_id' => null,
                    'active_rol_id' => null,
                ])->save();
            }
        }
    }

    /**
     * @param  list<int>  $rolIds
     */
    private function assignPersonaOrganizacion(
        Persona $persona,
        int $organizacionId,
        array $rolIds,
        ?string $fechaInicio = null,
        ?string $fechaFin = null,
        bool $estado = true,
        int $index = 0,
    ): void {
        $org = Organizacion::query()->find($organizacionId);
        if (! $org) {
            throw ValidationException::withMessages([
                "organizaciones.{$index}.organizacion_id" => ['La organización seleccionada no existe.'],
            ]);
        }

        $validRoleIds = Role::query()->whereIn('id', $rolIds)->pluck('id')->map(fn ($id) => (int) $id)->all();
        if (count($validRoleIds) !== count($rolIds)) {
            throw ValidationException::withMessages([
                "organizaciones.{$index}.rol_ids" => ['Uno o más roles de organización no existen.'],
            ]);
        }

        $personaOrg = PersonaOrganizacion::query()->firstOrCreate(
            [
                'persona_id' => $persona->id,
                'organizacion_id' => $organizacionId,
            ],
            [
                'fecha_inicio' => $fechaInicio ?: now()->toDateString(),
                'fecha_fin' => $fechaFin,
                'estado' => $estado,
            ],
        );

        $personaOrg->update([
            'estado' => $estado,
            'fecha_inicio' => $fechaInicio ?: ($personaOrg->fecha_inicio?->format('Y-m-d') ?: now()->toDateString()),
            'fecha_fin' => $estado ? null : ($fechaFin ?: now()->toDateString()),
        ]);

        // Sincronizar roles: agregar faltantes y quitar los que ya no aplican.
        $existingRoleIds = PersonaOrganizacionRol::query()
            ->where('persona_organizacion_id', $personaOrg->id)
            ->pluck('rol_id')
            ->map(fn ($id) => (int) $id)
            ->all();

        $toAdd = array_values(array_diff($validRoleIds, $existingRoleIds));
        $toRemove = array_values(array_diff($existingRoleIds, $validRoleIds));

        foreach ($toAdd as $rolId) {
            PersonaOrganizacionRol::query()->create([
                'persona_organizacion_id' => $personaOrg->id,
                'rol_id' => $rolId,
                'created_at' => now(),
            ]);
        }

        if ($toRemove !== []) {
            PersonaOrganizacionRol::query()
                ->where('persona_organizacion_id', $personaOrg->id)
                ->whereIn('rol_id', $toRemove)
                ->delete();
        }
    }

    /**
     * @param  list<int>  $roleIds
     */
    private function applyPlatformFlags(User $user, array $roleIds): void
    {
        $roleIds = array_values(array_unique(array_map('intval', $roleIds)));
        $roles = $roleIds === []
            ? collect()
            : Role::query()->whereIn('id', $roleIds)->get(['id', 'name', 'is_super']);

        $user->forceFill([
            'is_super' => $roles->contains(fn (Role $role) => $role->is_super || $role->name === 'super_admin'),
            'is_admin' => $roles->contains('name', 'admin'),
        ])->save();
        $user->clearPermissionCache();
    }

    /**
     * @param  list<int>|null  $clubIds
     * @param  list<int>|null  $pendingRoleIds
     */
    private function syncClubsForUser(User $user, ?array $clubIds, ?array $pendingRoleIds = null): void
    {
        $hasPastorRole = $user->hasRole('pastor');
        if (! $hasPastorRole && is_array($pendingRoleIds) && $pendingRoleIds !== []) {
            $hasPastorRole = Role::query()
                ->whereIn('id', $pendingRoleIds)
                ->where('name', 'pastor')
                ->exists();
        }

        if (! $hasPastorRole) {
            $user->clubs()->sync([]);

            return;
        }

        if (! is_array($clubIds)) {
            return;
        }

        $clubIds = array_values(array_unique(array_map('intval', $clubIds)));

        if ($clubIds !== []) {
            $taken = DB::table('club_user')
                ->whereIn('club_id', $clubIds)
                ->where('user_id', '!=', $user->id)
                ->pluck('club_id')
                ->all();

            if ($taken !== []) {
                $names = Club::query()
                    ->whereIn('id', $taken)
                    ->pluck('nombre')
                    ->implode(', ');

                throw ValidationException::withMessages([
                    'club_ids' => ["Estos clubes ya están asociados a otra cuenta Iglesia: {$names}."],
                ]);
            }
        }

        $user->clubs()->sync($clubIds);
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return array<string, mixed>
     */
    private function constrainOrganizationFilter(User $actor, array $filters): array
    {
        if (! $this->orgAccess->shouldScopeByOrganization($actor)) {
            return $filters;
        }

        $accessible = $this->orgAccess->accessibleOrganizationIds($actor);
        $requested = ! empty($filters['organizacion_id']) ? (int) $filters['organizacion_id'] : null;

        if ($requested && in_array($requested, $accessible, true)) {
            $filters['organizacion_id'] = $requested;
            unset($filters['organizacion_ids']);

            return $filters;
        }

        unset($filters['organizacion_id']);
        $filters['organizacion_ids'] = $accessible === [] ? [-1] : $accessible;

        return $filters;
    }
}
