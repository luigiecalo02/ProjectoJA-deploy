<?php

namespace App\Modules\Users\Repositories;

use App\Models\User;
use App\Modules\Organizations\Models\Organizacion;
use App\Modules\Organizations\Models\TipoOrganizacion;
use App\Modules\Organizations\Services\OrganizationAccessService;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;

final class UserRepository
{
    public function __construct(private readonly OrganizationAccessService $orgAccess) {}

    public function paginate(array $filters = [], int $perPage = 15): LengthAwarePaginator
    {
        $query = User::query()->with([
            'clubs',
            'persona.organizaciones.organizacion:id,nombre',
            'persona.organizaciones.rolesAsignados.rol:id,name,display_name',
        ]);

        if (! empty($filters['q'])) {
            $q = $filters['q'];
            $query->where(function ($builder) use ($q) {
                $builder->where('name', 'like', "%{$q}%")
                    ->orWhere('email', 'like', "%{$q}%")
                    ->orWhereHas('persona', function ($personaQuery) use ($q) {
                        $personaQuery->where('identificacion', 'like', "%{$q}%")
                            ->orWhere('nombre1', 'like', "%{$q}%")
                            ->orWhere('apellido1', 'like', "%{$q}%");
                    });
            });
        }

        if (isset($filters['is_active']) && $filters['is_active'] !== '') {
            $query->where('is_active', filter_var($filters['is_active'], FILTER_VALIDATE_BOOLEAN));
        }

        $scopeIds = $this->resolveScopeIds($filters);

        if (! empty($filters['role'])) {
            $this->constrainToRole($query, (string) $filters['role'], $scopeIds);
        } elseif ($scopeIds !== null) {
            $this->constrainToOrganizations($query, $scopeIds === [] ? [-1] : $scopeIds);
        }

        return $query->latest()->paginate($perPage);
    }

    public function findOrFail(int $id): User
    {
        return User::query()->with([
            'clubs',
            'persona.organizaciones.organizacion:id,nombre',
            'persona.organizaciones.rolesAsignados.rol:id,name,display_name',
        ])->findOrFail($id);
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return list<int>|null
     */
    private function resolveScopeIds(array $filters): ?array
    {
        $scopeIds = null;

        if (! empty($filters['organizacion_id'])) {
            $orgId = (int) $filters['organizacion_id'];
            $scopeIds = array_values(array_unique(array_merge(
                [$orgId],
                $this->orgAccess->descendantIds($orgId),
            )));
        } elseif (! empty($filters['organizacion_ids']) && is_array($filters['organizacion_ids'])) {
            $scopeIds = array_values(array_unique(array_map('intval', $filters['organizacion_ids'])));
        }

        $tipoClub = is_string($filters['tipo_club'] ?? null) ? strtolower(trim((string) $filters['tipo_club'])) : '';
        if ($tipoClub === '') {
            return $scopeIds;
        }

        $tipoOrgIds = $this->organizationIdsForMinistry($tipoClub);
        if ($scopeIds === null) {
            return $tipoOrgIds;
        }

        return array_values(array_intersect($scopeIds, $tipoOrgIds));
    }

    /**
     * @return list<int>
     */
    private function organizationIdsForMinistry(string $key): array
    {
        $tipoIds = $this->tipoIdsFromMinistry($key);
        if ($tipoIds === []) {
            return [];
        }

        return Organizacion::query()
            ->whereIn('tipo_organizacion_id', $tipoIds)
            ->pluck('id')
            ->map(fn ($id) => (int) $id)
            ->all();
    }

    /**
     * @return list<int>
     */
    private function tipoIdsFromMinistry(string $key): array
    {
        $patterns = match ($key) {
            'conquistadores' => ['%conquistador%'],
            'aventureros' => ['%aventurer%'],
            'guias_mayores' => ['%gu_a%mayor%', '%guia%mayor%'],
            default => [],
        };
        if ($patterns === []) {
            return [];
        }

        $ids = TipoOrganizacion::query()
            ->where(function ($query) use ($patterns) {
                foreach ($patterns as $index => $like) {
                    if ($index === 0) {
                        $query->where('nombre', 'like', $like);
                    } else {
                        $query->orWhere('nombre', 'like', $like);
                    }
                }
            })
            ->pluck('id')
            ->map(fn ($id) => (int) $id)
            ->all();

        if ($ids !== []) {
            return array_values(array_unique($ids));
        }

        $fallback = [
            'conquistadores' => Organizacion::TIPO_CONQUISTADORES,
            'aventureros' => Organizacion::TIPO_AVENTUREROS,
            'guias_mayores' => Organizacion::TIPO_GUIAS_MAYORES,
        ][$key] ?? null;

        return $fallback ? [(int) $fallback] : [];
    }

    /**
     * @param  Builder<User>  $query
     * @param  list<int>|null  $scopeIds
     */
    private function constrainToRole(Builder $query, string $role, ?array $scopeIds): void
    {
        if ($role === 'super_admin') {
            $query->where('is_super', true);
            if ($scopeIds !== null) {
                $this->constrainToOrganizations($query, $scopeIds === [] ? [-1] : $scopeIds);
            }

            return;
        }

        $orgIds = $scopeIds === [] ? [-1] : $scopeIds;

        $query->where(function (Builder $builder) use ($role, $orgIds) {
            $builder->whereHas('persona.organizaciones', function ($po) use ($role, $orgIds) {
                $po->where('estado', true)
                    ->when($orgIds !== null, fn ($scoped) => $scoped->whereIn('organizacion_id', $orgIds))
                    ->whereHas('rolesAsignados.rol', fn ($r) => $r->where('name', $role));
            });

            if ($role === 'admin' && $orgIds === null) {
                $builder->orWhere('is_admin', true);
            }
        });
    }

    /**
     * @param  Builder<User>  $query
     * @param  list<int>  $scopeIds
     */
    private function constrainToOrganizations(Builder $query, array $scopeIds): void
    {
        $query->where(function ($builder) use ($scopeIds) {
            $builder->whereHas('persona.organizaciones', function ($po) use ($scopeIds) {
                $po->where('estado', true)->whereIn('organizacion_id', $scopeIds);
            })->orWhereHas('clubs', function ($clubs) use ($scopeIds) {
                $clubs->whereIn('clubes.organizacion_id', $scopeIds);
            });
        });
    }
}
