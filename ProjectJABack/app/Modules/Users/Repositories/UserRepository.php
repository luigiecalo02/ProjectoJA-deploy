<?php

namespace App\Modules\Users\Repositories;

use App\Models\User;
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

        if (! empty($filters['role'])) {
            $role = (string) $filters['role'];
            $query->where(function ($builder) use ($role) {
                if ($role === 'super_admin') {
                    $builder->where('is_super', true);
                } elseif ($role === 'admin') {
                    $builder->where('is_admin', true);
                } else {
                    $builder->whereHas('persona.organizaciones', function ($po) use ($role) {
                        $po->where('estado', true)
                            ->whereHas('rolesAsignados.rol', fn ($r) => $r->where('name', $role));
                    });
                }
            });
        }

        if (! empty($filters['organizacion_id'])) {
            $orgId = (int) $filters['organizacion_id'];
            $scopeIds = array_values(array_unique(array_merge(
                [$orgId],
                $this->orgAccess->descendantIds($orgId),
            )));
            $this->constrainToOrganizations($query, $scopeIds);
        } elseif (! empty($filters['organizacion_ids']) && is_array($filters['organizacion_ids'])) {
            $scopeIds = array_values(array_unique(array_map('intval', $filters['organizacion_ids'])));
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
     * @param  Builder<User>  $query
     * @param  list<int>  $scopeIds
     */
    private function constrainToOrganizations(Builder $query, array $scopeIds): void
    {
        $query->whereHas('persona.organizaciones', function ($po) use ($scopeIds) {
            $po->where('estado', true)->whereIn('organizacion_id', $scopeIds);
        });
    }
}
