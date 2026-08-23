<?php

namespace App\Modules\Organizations\Services;

use App\Models\User;
use App\Modules\Clubs\Models\Club;
use App\Modules\Organizations\Models\Organizacion;
use App\Modules\Organizations\Models\PersonaOrganizacion;
use App\Modules\Users\Models\Role;

/**
 * Acceso por jerarquía organizacional vía persona_organizacion / persona_organizacion_rol.
 */
final class OrganizationAccessService
{
    /**
     * Super admin y Administrador en modo plataforma (sin org activa) ven todo el sistema.
     * Si eligen una organización concreta, se aplica el alcance de esa org.
     */
    public function bypassesOrganizationScope(User $actor): bool
    {
        return $actor->isPlatformAdmin() && $actor->active_organizacion_id === null;
    }

    public function shouldScopeByOrganization(User $actor): bool
    {
        return ! $this->bypassesOrganizationScope($actor);
    }

    /**
     * Organizaciones raíz a las que pertenece la persona del usuario (activas).
     *
     * @return list<int>
     */
    public function membershipOrganizationIds(User $actor): array
    {
        if ($actor->active_organizacion_id) {
            return [(int) $actor->active_organizacion_id];
        }

        if (! $actor->persona_id) {
            return [];
        }

        return PersonaOrganizacion::query()
            ->where('persona_id', $actor->persona_id)
            ->where('estado', true)
            ->pluck('organizacion_id')
            ->map(fn ($id) => (int) $id)
            ->unique()
            ->values()
            ->all();
    }

    /**
     * Organizaciones accesibles: membresías + todas las hijas en la jerarquía.
     *
     * @return list<int>
     */
    public function accessibleOrganizationIds(User $actor): array
    {
        if ($this->bypassesOrganizationScope($actor)) {
            return Organizacion::query()->pluck('id')->map(fn ($id) => (int) $id)->all();
        }

        $roots = $this->membershipOrganizationIds($actor);
        if ($roots === []) {
            return [];
        }

        $ids = $roots;
        foreach ($roots as $rootId) {
            $ids = array_merge($ids, $this->descendantIds($rootId));
        }

        return array_values(array_unique(array_map('intval', $ids)));
    }

    /**
     * Organización tipo Club del contexto de sesión activo (si aplica).
     */
    public function activeClubOrganizationId(User $actor): ?int
    {
        if (! $actor->active_organizacion_id) {
            return null;
        }

        $orgId = (int) $actor->active_organizacion_id;
        $tipoId = Organizacion::query()->where('id', $orgId)->value('tipo_organizacion_id');

        return (int) $tipoId === Organizacion::TIPO_CLUB ? $orgId : null;
    }

    /**
     * Iglesia padre del club activo (si el contexto es un club).
     */
    public function parentChurchOrganizationId(User $actor): ?int
    {
        $clubOrgId = $this->activeClubOrganizationId($actor);
        if ($clubOrgId === null) {
            return null;
        }

        $parentId = Organizacion::query()
            ->where('id', $clubOrgId)
            ->value('organizacion_padre_id');

        if (! $parentId) {
            return null;
        }

        return (int) $parentId;
    }

    /**
     * Clubes hermanos (mismo padre) excluyendo el club activo.
     *
     * @return list<int>
     */
    public function siblingClubOrganizationIds(User $actor): array
    {
        $parentId = $this->parentChurchOrganizationId($actor);
        $currentClubId = $this->activeClubOrganizationId($actor);
        if ($parentId === null) {
            return [];
        }

        return Organizacion::query()
            ->where('organizacion_padre_id', $parentId)
            ->where('tipo_organizacion_id', Organizacion::TIPO_CLUB)
            ->when($currentClubId !== null, fn ($q) => $q->where('id', '!=', $currentClubId))
            ->pluck('id')
            ->map(fn ($id) => (int) $id)
            ->values()
            ->all();
    }

    /**
     * Pool de Personas (no integrantes del club activo): iglesia padre + clubes hermanos.
     *
     * @return list<int>
     */
    public function personaPoolOrganizationIds(User $actor): array
    {
        $parentId = $this->parentChurchOrganizationId($actor);
        $ids = $this->siblingClubOrganizationIds($actor);
        if ($parentId !== null) {
            $ids[] = $parentId;
        }

        return array_values(array_unique($ids));
    }

    /**
     * Orgs que el actor puede ver al consultar una persona (club + iglesia + hermanos).
     *
     * @return list<int>
     */
    public function personaAccessibleOrganizationIds(User $actor): array
    {
        $ids = $this->accessibleOrganizationIds($actor);
        $clubId = $this->activeClubOrganizationId($actor);
        if ($clubId !== null) {
            $ids = array_merge($ids, $this->personaPoolOrganizationIds($actor), [$clubId]);
        }

        return array_values(array_unique(array_map('intval', $ids)));
    }

    /**
     * Organizaciones usadas para listar personas según el contexto activo.
     * - Contexto tipo Club: solo esa organización.
     * - Otro contexto: org activa + descendientes.
     * - Modo plataforma sin org: null (sin filtro).
     *
     * @return list<int>|null
     */
    public function personaListOrganizationIds(User $actor): ?array
    {
        if ($actor->active_organizacion_id) {
            $rootId = (int) $actor->active_organizacion_id;
            $tipoId = (int) (Organizacion::query()->where('id', $rootId)->value('tipo_organizacion_id') ?? 0);

            if ($tipoId === Organizacion::TIPO_CLUB) {
                return [$rootId];
            }

            return array_values(array_unique(array_merge(
                [$rootId],
                $this->descendantIds($rootId),
            )));
        }

        if ($this->bypassesOrganizationScope($actor)) {
            return null;
        }

        return $this->accessibleOrganizationIds($actor);
    }

    /**
     * Clubes cuyo organizacion_id está en el alcance del actor.
     *
     * @return list<int>
     */
    public function accessibleClubIds(User $actor): array
    {
        if ($this->bypassesOrganizationScope($actor)) {
            return Club::query()->pluck('id')->map(fn ($id) => (int) $id)->all();
        }

        $orgIds = $this->accessibleOrganizationIds($actor);
        if ($orgIds === []) {
            return [];
        }

        return Club::query()
            ->whereIn('organizacion_id', $orgIds)
            ->pluck('id')
            ->map(fn ($id) => (int) $id)
            ->all();
    }

    public function canAccessOrganization(User $actor, int $organizacionId): bool
    {
        if ($this->bypassesOrganizationScope($actor)) {
            return true;
        }

        return in_array($organizacionId, $this->accessibleOrganizationIds($actor), true);
    }

    public function canAccessClub(User $actor, Club $club): bool
    {
        if ($this->bypassesOrganizationScope($actor)) {
            return true;
        }

        if ($club->organizacion_id) {
            return $this->canAccessOrganization($actor, (int) $club->organizacion_id);
        }

        // Club sin org: fallback legacy club_user
        return $club->users()->where('users.id', $actor->id)->exists();
    }

    /**
     * Roles activos vía persona_organizacion_rol (sesión organizacional).
     *
     * @return list<Role>
     */
    public function organizationalRoles(User $actor): array
    {
        if (! $actor->persona_id) {
            return [];
        }

        return Role::query()
            ->whereIn('id', function ($query) use ($actor) {
                $query->select('persona_organizacion_rol.rol_id')
                    ->from('persona_organizacion_rol')
                    ->join(
                        'persona_organizacion',
                        'persona_organizacion.id',
                        '=',
                        'persona_organizacion_rol.persona_organizacion_id'
                    )
                    ->where('persona_organizacion.persona_id', $actor->persona_id)
                    ->where('persona_organizacion.estado', true);
            })
            ->with('permissions')
            ->orderBy('sort_order')
            ->get()
            ->all();
    }

    /**
     * Contexto de organizaciones + roles para el payload de sesión.
     *
     * @return list<array<string, mixed>>
     */
    public function sessionOrganizaciones(User $actor): array
    {
        if (! $actor->persona_id) {
            return [];
        }

        $rows = PersonaOrganizacion::query()
            ->with(['organizacion:id,nombre,codigo,tipo_organizacion_id', 'rolesAsignados.rol:id,name,display_name'])
            ->where('persona_id', $actor->persona_id)
            ->where('estado', true)
            ->get();

        return $rows->map(function (PersonaOrganizacion $po) {
            return [
                'id' => $po->id,
                'organizacion_id' => $po->organizacion_id,
                'organizacion_nombre' => $po->organizacion?->nombre,
                'organizacion_codigo' => $po->organizacion?->codigo,
                'tipo_organizacion_id' => $po->organizacion?->tipo_organizacion_id,
                'fecha_inicio' => optional($po->fecha_inicio)?->format('Y-m-d'),
                'roles' => $po->rolesAsignados->map(fn ($r) => [
                    'id' => $r->id,
                    'rol_id' => $r->rol_id,
                    'name' => $r->rol?->name,
                    'display_name' => $r->rol?->display_name ?: $r->rol?->name,
                ])->values()->all(),
            ];
        })->values()->all();
    }

    /**
     * ¿La organización no tiene hijas? (última generación).
     */
    public function isLeafOrganization(int $organizacionId): bool
    {
        return ! Organizacion::query()
            ->where('organizacion_padre_id', $organizacionId)
            ->exists();
    }

    /**
     * Organizaciones a las que el actor puede asociar una persona nueva.
     * - Admin: todas
     * - Membresía hoja: solo esa(s)
     * - Membresía padre: todas las hijas (descendientes)
     *
     * @return list<int>
     */
    public function assignableOrganizationIdsForPersona(User $actor, bool $soloTipoClub = false): array
    {
        $activeClubOrgId = $this->activeClubOrganizationId($actor);
        if ($activeClubOrgId !== null) {
            if ($soloTipoClub) {
                return [$activeClubOrgId];
            }

            return $this->personaPoolOrganizationIds($actor);
        }

        if ($this->bypassesOrganizationScope($actor)) {
            return Organizacion::query()->pluck('id')->map(fn ($id) => (int) $id)->all();
        }

        $memberships = $this->membershipOrganizationIds($actor);
        if ($memberships === []) {
            return [];
        }

        $leafIds = [];
        $parentIds = [];
        foreach ($memberships as $orgId) {
            if ($this->isLeafOrganization($orgId)) {
                $leafIds[] = $orgId;
            } else {
                $parentIds[] = $orgId;
            }
        }

        if ($parentIds === []) {
            return array_values(array_unique($leafIds));
        }

        $ids = $leafIds;
        foreach ($parentIds as $rootId) {
            $descendants = $this->descendantIds($rootId);
            if ($descendants === []) {
                // Padre sin hijas aún: permitir asociar al propio nodo
                $ids[] = $rootId;
            } else {
                $ids = array_merge($ids, $descendants);
            }
        }

        return array_values(array_unique(array_map('intval', $ids)));
    }

    /**
     * Prefill al crear persona: solo si todas las membresías son hoja.
     *
     * @return list<int>
     */
    public function defaultPersonaOrganizationIds(User $actor, bool $soloTipoClub = false): array
    {
        $activeClubOrgId = $this->activeClubOrganizationId($actor);
        if ($activeClubOrgId !== null) {
            if ($soloTipoClub) {
                return [$activeClubOrgId];
            }

            $parentId = $this->parentChurchOrganizationId($actor);

            return $parentId !== null ? [$parentId] : [];
        }

        if ($this->bypassesOrganizationScope($actor)) {
            return [];
        }

        $memberships = $this->membershipOrganizationIds($actor);
        if ($memberships === []) {
            return [];
        }

        foreach ($memberships as $orgId) {
            if (! $this->isLeafOrganization($orgId)) {
                return [];
            }
        }

        return $memberships;
    }

    /**
     * @return list<int>
     */
    public function descendantIds(int $rootId): array
    {
        $ids = [];
        $frontier = [$rootId];

        while ($frontier !== []) {
            $children = Organizacion::query()
                ->whereIn('organizacion_padre_id', $frontier)
                ->pluck('id')
                ->all();

            $new = array_values(array_diff($children, $ids));
            if ($new === []) {
                break;
            }
            $ids = array_merge($ids, $new);
            $frontier = $new;
        }

        return array_map('intval', $ids);
    }
}
