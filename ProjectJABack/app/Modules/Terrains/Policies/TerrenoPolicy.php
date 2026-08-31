<?php

namespace App\Modules\Terrains\Policies;

use App\Models\User;
use App\Modules\Terrains\Models\Terreno;

final class TerrenoPolicy
{
    public function viewAny(User $actor): bool
    {
        return $actor->hasCatalogPermission('terrenos', 'view');
    }

    public function view(User $actor, Terreno $terreno): bool
    {
        return $actor->hasCatalogPermission('terrenos', 'view');
    }

    public function create(User $actor): bool
    {
        return $actor->hasCatalogPermission('terrenos', 'create');
    }

    public function update(User $actor, Terreno $terreno): bool
    {
        return $actor->hasCatalogPermission('terrenos', 'update');
    }

    public function delete(User $actor, Terreno $terreno): bool
    {
        return $actor->hasCatalogPermission('terrenos', 'delete');
    }

    public function assign(User $actor, Terreno $terreno): bool
    {
        return $actor->hasPermission('terrenos.assign')
            || $actor->hasCatalogPermission('terrenos', 'update');
    }

    public function overrideCapacity(User $actor): bool
    {
        return $actor->hasPermission('terrenos.override_capacity');
    }
}
