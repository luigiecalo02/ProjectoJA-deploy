<?php

namespace App\Modules\Users\Policies;

use App\Models\User;
use App\Modules\Users\Models\Role;

final class RolePolicy
{
    public function viewAny(User $actor): bool
    {
        return $actor->hasPermission('roles.view');
    }

    public function view(User $actor, Role $role): bool
    {
        return $actor->hasPermission('roles.view');
    }

    public function create(User $actor): bool
    {
        return $actor->hasPermission('roles.create');
    }

    public function update(User $actor, Role $role): bool
    {
        return $actor->hasPermission('roles.update');
    }

    public function delete(User $actor, Role $role): bool
    {
        if ($role->is_system || $role->is_super) {
            return false;
        }

        return $actor->hasPermission('roles.delete');
    }

    public function assignPermissions(User $actor, Role $role): bool
    {
        if ($role->is_super) {
            return false;
        }

        return $actor->hasPermission('roles.assign_permissions') || $actor->hasPermission('roles.update');
    }
}
