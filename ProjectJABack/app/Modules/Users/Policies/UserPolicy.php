<?php

namespace App\Modules\Users\Policies;

use App\Models\User;

final class UserPolicy
{
    public function viewAny(User $actor): bool
    {
        return $actor->hasPermission('users.view');
    }

    public function view(User $actor, User $user): bool
    {
        return $actor->hasPermission('users.view') || $actor->id === $user->id;
    }

    public function create(User $actor): bool
    {
        return $actor->hasPermission('users.create');
    }

    public function update(User $actor, User $user): bool
    {
        return $actor->hasPermission('users.update') || $actor->id === $user->id;
    }

    public function delete(User $actor, User $user): bool
    {
        return $actor->hasPermission('users.delete') && $actor->id !== $user->id;
    }

    public function assignRoles(User $actor): bool
    {
        return $actor->hasPermission('users.assign_roles');
    }

    public function impersonate(User $actor, User $user): bool
    {
        if ($actor->id === $user->id || ! $user->is_active) {
            return false;
        }

        if ($user->isSuperAdmin() && ! $actor->isSuperAdmin()) {
            return false;
        }

        return $actor->isPlatformAdmin() || $actor->hasPermission('users.view');
    }
}
