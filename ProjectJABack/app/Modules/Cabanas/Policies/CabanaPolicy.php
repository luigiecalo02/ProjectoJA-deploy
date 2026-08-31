<?php

namespace App\Modules\Cabanas\Policies;

use App\Models\User;
use App\Modules\Cabanas\Models\Cabana;

final class CabanaPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasCatalogPermission('cabanas', 'view');
    }

    public function view(User $user, Cabana $cabana): bool
    {
        return $user->hasCatalogPermission('cabanas', 'view');
    }

    public function create(User $user): bool
    {
        return $user->hasCatalogPermission('cabanas', 'create');
    }

    public function update(User $user, Cabana $cabana): bool
    {
        return $user->hasCatalogPermission('cabanas', 'update');
    }

    public function delete(User $user, Cabana $cabana): bool
    {
        return $user->hasCatalogPermission('cabanas', 'delete');
    }
}
