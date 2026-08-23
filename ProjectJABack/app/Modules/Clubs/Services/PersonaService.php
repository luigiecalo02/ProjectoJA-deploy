<?php

namespace App\Modules\Clubs\Services;

use App\Models\User;
use App\Modules\Clubs\Models\Club;
use App\Modules\Clubs\Models\Persona;
use App\Modules\Organizations\Models\Organizacion;
use App\Modules\Organizations\Models\PersonaOrganizacion;
use App\Modules\Organizations\Services\OrganizationAccessService;
use App\Modules\Shared\Services\AuditLogger;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class PersonaService
{
    public function __construct(
        private readonly AuditLogger $auditLogger,
        private readonly OrganizationAccessService $orgAccess,
    ) {}

    public function shouldScopeToOwnedClubs(User $actor): bool
    {
        return $this->orgAccess->shouldScopeByOrganization($actor);
    }

    public function actorCanAccess(User $actor, Persona $persona): bool
    {
        if (! $this->orgAccess->shouldScopeByOrganization($actor)) {
            return true;
        }

        $orgIds = $this->orgAccess->personaAccessibleOrganizationIds($actor);
        if ($orgIds === []) {
            return false;
        }

        return $persona->organizaciones()
            ->whereIn('organizacion_id', $orgIds)
            ->where('estado', true)
            ->exists();
    }

    /**
     * Alcance del directorio: integrantes = club activo; personas = iglesia padre + hermanos.
     *
     * @return list<int>|null
     */
    private function personaDirectoryOrganizationIds(User $actor, bool $soloTipoClub): ?array
    {
        if ($this->orgAccess->activeClubOrganizationId($actor) !== null && ! $soloTipoClub) {
            return $this->orgAccess->personaPoolOrganizationIds($actor);
        }

        return $this->orgAccess->personaListOrganizationIds($actor);
    }

    /**
     * Opciones de organización asociadas al alcance del actor.
     * Con $soloTipoClub=true solo retorna organizaciones tipo Club (vista Integrantes).
     *
     * @return array{mode: string, locked: bool, default_ids: list<int>, options: list<array<string, mixed>>}
     */
    public function organizacionOptions(User $actor, bool $soloTipoClub = false): array
    {
        $assignableIds = $this->orgAccess->assignableOrganizationIdsForPersona($actor, $soloTipoClub);
        $mode = $this->orgAccess->bypassesOrganizationScope($actor)
            ? 'admin'
            : 'parent';

        $query = $assignableIds === []
            ? null
            : Organizacion::query()
                ->with('tipo:id,nombre')
                ->whereIn('id', $assignableIds)
                ->where('estado', true)
                ->orderBy('nombre');

        if ($query && $soloTipoClub) {
            $query->where('tipo_organizacion_id', Organizacion::TIPO_CLUB);
        }

        $orgs = $query
            ? $query->get(['id', 'nombre', 'codigo', 'tipo_organizacion_id', 'organizacion_padre_id'])
            : collect();

        $parentsById = collect();
        $grandparentsById = collect();
        $parentIds = $orgs->pluck('organizacion_padre_id')->filter()->map(fn ($id) => (int) $id)->unique()->values()->all();
        if ($parentIds !== []) {
            $parentsById = Organizacion::query()
                ->whereIn('id', $parentIds)
                ->get(['id', 'nombre', 'organizacion_padre_id'])
                ->keyBy(fn (Organizacion $o) => (int) $o->id);

            $grandparentIds = $parentsById
                ->pluck('organizacion_padre_id')
                ->filter()
                ->map(fn ($id) => (int) $id)
                ->unique()
                ->values()
                ->all();

            if ($grandparentIds !== []) {
                $grandparentsById = Organizacion::query()
                    ->whereIn('id', $grandparentIds)
                    ->get(['id', 'nombre'])
                    ->keyBy(fn (Organizacion $o) => (int) $o->id);
            }
        }

        $optionIds = $orgs->pluck('id')->map(fn ($id) => (int) $id)->values()->all();
        $defaultIds = array_values(array_intersect(
            $this->orgAccess->defaultPersonaOrganizationIds($actor, $soloTipoClub),
            $optionIds,
        ));

        // Una sola opción en el alcance: se asocia automáticamente.
        if ($defaultIds === [] && count($optionIds) === 1 && $mode !== 'admin') {
            $defaultIds = $optionIds;
        }

        if ($mode !== 'admin' && $defaultIds !== [] && count($optionIds) === count($defaultIds)) {
            $mode = 'leaf';
        }

        return [
            'mode' => $mode,
            'locked' => count($optionIds) === 1,
            'default_ids' => $defaultIds,
            'options' => $orgs->map(function (Organizacion $org) use ($parentsById, $grandparentsById) {
                $padre = $org->organizacion_padre_id
                    ? $parentsById->get((int) $org->organizacion_padre_id)
                    : null;
                $abuelo = $padre?->organizacion_padre_id
                    ? $grandparentsById->get((int) $padre->organizacion_padre_id)
                    : null;

                return [
                    'id' => $org->id,
                    'nombre' => $org->nombre,
                    'codigo' => $org->codigo,
                    'tipo_organizacion_id' => $org->tipo_organizacion_id,
                    'tipo_nombre' => $org->tipo?->nombre,
                    'organizacion_padre_id' => $org->organizacion_padre_id,
                    'padre_nombre' => $padre?->nombre,
                    'abuelo_nombre' => $abuelo?->nombre,
                    'is_leaf' => $this->orgAccess->isLeafOrganization((int) $org->id),
                ];
            })->values()->all(),
        ];
    }

    public function list(User $actor, array $filters = [], int $perPage = 15): LengthAwarePaginator
    {
        $query = Persona::query()
            ->with([
                'user:id,persona_id,name,email',
                'organizaciones.organizacion:id,nombre,codigo,organizacion_padre_id,tipo_organizacion_id',
            ]);

        $soloTipoClub = array_key_exists('solo_tipo_club', $filters)
            && $filters['solo_tipo_club'] !== null
            && $filters['solo_tipo_club'] !== ''
            && filter_var($filters['solo_tipo_club'], FILTER_VALIDATE_BOOLEAN);
        $activeClubOrgId = $this->orgAccess->activeClubOrganizationId($actor);

        $familyOrgIds = null;
        if (! empty($filters['organizacion_padre_id'])) {
            $padreId = (int) $filters['organizacion_padre_id'];
            $familyOrgIds = Organizacion::query()
                ->where(function ($q) use ($padreId) {
                    $q->where('organizacion_padre_id', $padreId)->orWhere('id', $padreId);
                })
                ->pluck('id')
                ->map(fn ($id) => (int) $id)
                ->values()
                ->all();

            $canSeeFamily = $this->orgAccess->bypassesOrganizationScope($actor)
                || $this->orgAccess->canAccessOrganization($actor, $padreId);

            if (! $canSeeFamily) {
                $scopeOrgIds = $this->personaDirectoryOrganizationIds($actor, $soloTipoClub) ?? [];
                $canSeeFamily = $familyOrgIds !== [] && array_intersect($scopeOrgIds, $familyOrgIds) !== [];
            }

            if (! $canSeeFamily || $familyOrgIds === []) {
                $query->whereRaw('1 = 0');
            } else {
                $query->whereHas(
                    'organizaciones',
                    fn ($q) => $q->whereIn('organizacion_id', $familyOrgIds)->where('estado', true)
                );
            }
        } else {
            $scopeOrgIds = $this->personaDirectoryOrganizationIds($actor, $soloTipoClub);
            if ($scopeOrgIds !== null) {
                if ($scopeOrgIds === []) {
                    $query->whereRaw('1 = 0');
                } else {
                    $query->whereHas(
                        'organizaciones',
                        fn ($q) => $q->whereIn('organizacion_id', $scopeOrgIds)->where('estado', true)
                    );
                }
            }
        }

        if ($activeClubOrgId !== null && ! $soloTipoClub) {
            $query->whereDoesntHave(
                'organizaciones',
                fn ($q) => $q->where('organizacion_id', $activeClubOrgId)->where('estado', true)
            );
        }

        if (! empty($filters['organizacion_id'])) {
            $filterOrgId = (int) $filters['organizacion_id'];
            $query->whereHas(
                'organizaciones',
                fn ($q) => $q->where('organizacion_id', $filterOrgId)->where('estado', true)
            );
        }

        if ($soloTipoClub) {
            $query->whereHas(
                'organizaciones',
                function ($q) {
                    $q->where('estado', true)
                        ->whereHas(
                            'organizacion',
                            fn ($org) => $org->where('tipo_organizacion_id', Organizacion::TIPO_CLUB)
                        );
                }
            );
        }

        if (! empty($filters['q'])) {
            $q = trim((string) $filters['q']);
            $query->where(function ($inner) use ($q) {
                $inner->where('identificacion', 'like', "%{$q}%")
                    ->orWhere('nombre1', 'like', "%{$q}%")
                    ->orWhere('nombre2', 'like', "%{$q}%")
                    ->orWhere('apellido1', 'like', "%{$q}%")
                    ->orWhere('apellido2', 'like', "%{$q}%")
                    ->orWhere('correo', 'like', "%{$q}%");
            });
        }

        if (array_key_exists('sin_usuario', $filters) && $filters['sin_usuario'] !== null && $filters['sin_usuario'] !== '') {
            if (filter_var($filters['sin_usuario'], FILTER_VALIDATE_BOOLEAN)) {
                $query->whereDoesntHave('user');
            } else {
                $query->whereHas('user');
            }
        }

        return $query->orderBy('apellido1')->orderBy('nombre1')->paginate($perPage);
    }

    public function find(int $id): Persona
    {
        return Persona::query()
            ->with([
                'user:id,persona_id,name,email',
                'organizaciones.organizacion:id,nombre,codigo',
            ])
            ->findOrFail($id);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function create(array $data, User $actor): Persona
    {
        $clubIds = $this->normalizeIds($data['club_ids'] ?? null);
        $orgIds = $this->normalizeIds($data['organizacion_ids'] ?? null);
        $soloTipoClub = filter_var($data['solo_tipo_club'] ?? false, FILTER_VALIDATE_BOOLEAN);
        unset($data['club_ids'], $data['organizacion_ids'], $data['solo_tipo_club']);

        // Integrantes de club: la persona queda ligada a la org tipo Club del club.
        if ($clubIds !== []) {
            $orgFromClubs = Club::query()
                ->whereIn('id', $clubIds)
                ->whereNotNull('organizacion_id')
                ->pluck('organizacion_id')
                ->map(fn ($id) => (int) $id)
                ->unique()
                ->values()
                ->all();

            if ($orgFromClubs !== []) {
                $orgIds = $orgFromClubs;
                $soloTipoClub = true;
            }
        }

        if ($orgIds === []) {
            $orgIds = $this->orgAccess->defaultPersonaOrganizationIds($actor, $soloTipoClub);
        }

        if ($orgIds === []) {
            throw ValidationException::withMessages([
                'organizacion_ids' => ['Debes asociar la persona al menos a una organización.'],
            ]);
        }

        if ($this->orgAccess->shouldScopeByOrganization($actor)) {
            $this->assertActorCanAssignOrgs($actor, $orgIds, null, $soloTipoClub);
        } else {
            $this->assertOrgsExist($orgIds);
        }

        if ($clubIds === [] && $orgIds !== []) {
            $clubIds = $this->clubIdsForOrganizaciones($orgIds);
        }

        if ($clubIds !== []) {
            if ($this->shouldScopeToOwnedClubs($actor)) {
                $this->assertActorOwnsClubs($actor, $clubIds);
            } else {
                $this->assertClubsExist($clubIds);
            }
        }

        return DB::transaction(function () use ($data, $orgIds) {
            $persona = Persona::query()->create($data);
            $this->syncOrganizaciones($persona, $orgIds);

            $this->auditLogger->log('personas', 'create', null, $persona->fresh(['organizaciones'])->toArray(), $persona);

            return $persona->fresh(['user', 'organizaciones.organizacion']);
        });
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function update(Persona $persona, array $data, User $actor): Persona
    {
        $hasClubs = array_key_exists('club_ids', $data);
        $hasOrgs = array_key_exists('organizacion_ids', $data);
        $clubIds = $hasClubs ? $this->normalizeIds($data['club_ids']) : null;
        $orgIds = $hasOrgs ? $this->normalizeIds($data['organizacion_ids']) : null;
        $soloTipoClub = filter_var($data['solo_tipo_club'] ?? false, FILTER_VALIDATE_BOOLEAN);
        unset($data['club_ids'], $data['organizacion_ids'], $data['solo_tipo_club']);

        if ($hasClubs && ! $hasOrgs && ($clubIds ?? []) !== []) {
            $orgIds = Club::query()
                ->whereIn('id', $clubIds ?? [])
                ->whereNotNull('organizacion_id')
                ->pluck('organizacion_id')
                ->map(fn ($id) => (int) $id)
                ->unique()
                ->values()
                ->all();
            $hasOrgs = true;
        }

        if ($hasOrgs && $this->orgAccess->shouldScopeByOrganization($actor)) {
            if (($orgIds ?? []) === []) {
                // Vacío solo es válido si ya hay orgs activas fuera del alcance (se preservan).
                $assignable = $this->orgAccess->assignableOrganizationIdsForPersona($actor, $soloTipoClub);
                $hasProtected = PersonaOrganizacion::query()
                    ->where('persona_id', $persona->id)
                    ->where('estado', true)
                    ->when(
                        $assignable !== [],
                        fn ($q) => $q->whereNotIn('organizacion_id', $assignable),
                        fn ($q) => $q,
                    )
                    ->exists();
                if (! $hasProtected) {
                    throw ValidationException::withMessages([
                        'organizacion_ids' => ['Debes asociar la persona al menos a una organización.'],
                    ]);
                }
            } else {
                $this->assertActorCanAssignOrgs($actor, $orgIds ?? [], $persona, $soloTipoClub);
            }
        } elseif ($hasOrgs && ($orgIds ?? []) !== []) {
            $this->assertOrgsExist($orgIds ?? []);
        }

        if ($hasClubs && ($clubIds ?? []) !== []) {
            if ($this->shouldScopeToOwnedClubs($actor)) {
                $this->assertActorOwnsClubs($actor, $clubIds ?? []);
            } else {
                $this->assertClubsExist($clubIds ?? []);
            }
        }

        return DB::transaction(function () use ($persona, $data, $hasOrgs, $orgIds, $actor, $soloTipoClub) {
            $old = $persona->toArray();
            $persona->update($data);

            if ($hasOrgs) {
                $this->syncOrganizaciones($persona, $orgIds ?? [], $actor, $soloTipoClub);
            }

            $this->auditLogger->log('personas', 'update', $old, $persona->fresh(['organizaciones'])->toArray(), $persona);

            return $persona->fresh(['user', 'organizaciones.organizacion']);
        });
    }

    public function delete(Persona $persona): void
    {
        $old = $persona->toArray();
        User::query()->where('persona_id', $persona->id)->update(['persona_id' => null]);
        $persona->delete();
        $this->auditLogger->log('personas', 'delete', $old, null, $persona);
    }

    /**
     * @param  list<int>  $orgIds
     */
    private function syncOrganizaciones(
        Persona $persona,
        array $orgIds,
        ?User $actor = null,
        bool $soloTipoClub = false,
    ): void {
        $orgIds = array_values(array_unique($orgIds));
        $assignable = null;

        if ($actor && $this->orgAccess->shouldScopeByOrganization($actor)) {
            $assignable = $this->orgAccess->assignableOrganizationIdsForPersona($actor, $soloTipoClub);
            $existingActive = PersonaOrganizacion::query()
                ->where('persona_id', $persona->id)
                ->where('estado', true)
                ->pluck('organizacion_id')
                ->map(fn ($id) => (int) $id)
                ->all();

            // Orgs activas fuera del alcance: no se pueden quitar ni alterar.
            $protected = array_values(array_diff($existingActive, $assignable));
            $editableRequested = array_values(array_intersect($orgIds, $assignable));
            $orgIds = array_values(array_unique(array_merge($protected, $editableRequested)));
        }

        $existing = PersonaOrganizacion::query()
            ->where('persona_id', $persona->id)
            ->get()
            ->keyBy('organizacion_id');

        $keep = [];
        foreach ($orgIds as $orgId) {
            $row = $existing->get($orgId);
            if ($row) {
                if (! $row->estado) {
                    $row->update([
                        'estado' => true,
                        'fecha_inicio' => $row->fecha_inicio ?: now()->toDateString(),
                        'fecha_fin' => null,
                    ]);
                }
                $keep[] = $row->id;
            } else {
                $created = PersonaOrganizacion::query()->create([
                    'persona_id' => $persona->id,
                    'organizacion_id' => $orgId,
                    'fecha_inicio' => now()->toDateString(),
                    'estado' => true,
                ]);
                $keep[] = $created->id;
            }
        }

        // Desactivar solo las del alcance (o todas si no hay scope).
        $deactivate = PersonaOrganizacion::query()
            ->where('persona_id', $persona->id)
            ->when($keep !== [], fn ($q) => $q->whereNotIn('id', $keep), fn ($q) => $q);

        if ($assignable !== null) {
            $deactivate->whereIn('organizacion_id', $assignable);
        }

        $deactivate->update([
            'estado' => false,
            'fecha_fin' => now()->toDateString(),
        ]);
    }

    /**
     * @param  list<int>|mixed  $ids
     * @return list<int>
     */
    private function normalizeIds(mixed $ids): array
    {
        if (! is_array($ids)) {
            return [];
        }

        return array_values(array_unique(array_map('intval', $ids)));
    }

    /**
     * Clubes vinculados a las organizaciones tipo Club seleccionadas.
     *
     * @param  list<int>  $orgIds
     * @return list<int>
     */
    private function clubIdsForOrganizaciones(array $orgIds): array
    {
        if ($orgIds === []) {
            return [];
        }

        return Club::query()
            ->whereIn('organizacion_id', $orgIds)
            ->pluck('id')
            ->map(fn ($id) => (int) $id)
            ->values()
            ->all();
    }

    /**
     * @param  list<int>  $orgIds
     */
    private function assertActorCanAssignOrgs(
        User $actor,
        array $orgIds,
        ?Persona $persona = null,
        bool $soloTipoClub = false,
    ): void {
        $allowed = $this->orgAccess->assignableOrganizationIdsForPersona($actor, $soloTipoClub);

        // En edición se permiten orgs ya asociadas fuera de alcance (solo lectura).
        if ($persona) {
            $protected = PersonaOrganizacion::query()
                ->where('persona_id', $persona->id)
                ->where('estado', true)
                ->whereNotIn('organizacion_id', $allowed === [] ? [0] : $allowed)
                ->pluck('organizacion_id')
                ->map(fn ($id) => (int) $id)
                ->all();
            $allowed = array_values(array_unique(array_merge($allowed, $protected)));
        }

        $forbidden = array_values(array_diff($orgIds, $allowed));
        if ($forbidden !== []) {
            throw ValidationException::withMessages([
                'organizacion_ids' => ['Solo puedes asociar personas a organizaciones de tu alcance.'],
            ]);
        }
    }

    /**
     * @param  list<int>  $orgIds
     */
    private function assertOrgsAreClubs(array $orgIds): void
    {
        if ($orgIds === []) {
            return;
        }

        $notClubs = Organizacion::query()
            ->whereIn('id', $orgIds)
            ->where('tipo_organizacion_id', '!=', Organizacion::TIPO_CLUB)
            ->pluck('id')
            ->all();

        if ($notClubs !== []) {
            throw ValidationException::withMessages([
                'organizacion_ids' => ['Solo puedes asociar la persona a organizaciones de tipo Club.'],
            ]);
        }
    }

    /**
     * @param  list<int>  $orgIds
     */
    private function assertOrgsExist(array $orgIds): void
    {
        $existing = Organizacion::query()->whereIn('id', $orgIds)->pluck('id')->all();
        if (count($existing) !== count($orgIds)) {
            throw ValidationException::withMessages([
                'organizacion_ids' => ['Una o más organizaciones no existen.'],
            ]);
        }
    }

    /**
     * @param  list<int>  $clubIds
     */
    private function assertActorOwnsClubs(User $actor, array $clubIds): void
    {
        $accessible = $this->orgAccess->accessibleClubIds($actor);
        $forbidden = array_values(array_diff($clubIds, $accessible));

        if ($forbidden !== []) {
            $owned = $actor->clubs()->whereIn('clubes.id', $clubIds)->pluck('clubes.id')->all();
            $stillForbidden = array_values(array_diff($clubIds, $owned));
            if ($stillForbidden !== []) {
                throw ValidationException::withMessages([
                    'club_ids' => ['Solo puedes asociar personas a clubes de tu organización.'],
                ]);
            }
        }
    }

    /**
     * @param  list<int>  $clubIds
     */
    private function assertClubsExist(array $clubIds): void
    {
        $existing = Club::query()->whereIn('id', $clubIds)->pluck('id')->all();
        if (count($existing) !== count($clubIds)) {
            throw ValidationException::withMessages([
                'club_ids' => ['Uno o más clubes no existen.'],
            ]);
        }
    }
}
