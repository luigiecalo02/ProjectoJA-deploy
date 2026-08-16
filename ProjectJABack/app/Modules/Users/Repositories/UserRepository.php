<?php

namespace App\Modules\Users\Repositories;

use App\Models\User;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

final class UserRepository
{
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
}
