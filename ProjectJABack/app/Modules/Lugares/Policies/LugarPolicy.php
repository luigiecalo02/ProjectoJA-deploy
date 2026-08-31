<?php

namespace App\Modules\Lugares\Policies;

use App\Models\User;
use App\Modules\Lugares\Models\Lugar;

final class LugarPolicy
{
    public function viewAny(User $actor): bool
    {
        return $this->canPick($actor) || $actor->hasPermission('lugares.view');
    }

    public function view(User $actor, Lugar $lugar): bool
    {
        return $this->viewAny($actor);
    }

    public function create(User $actor): bool
    {
        return $actor->hasPermission('lugares.create');
    }

    public function update(User $actor, Lugar $lugar): bool
    {
        return $actor->hasPermission('lugares.update');
    }

    public function delete(User $actor, Lugar $lugar): bool
    {
        return $actor->hasPermission('lugares.delete');
    }

    private function canPick(User $actor): bool
    {
        return $actor->hasPermission('lugares.view')
            || $actor->hasPermission('events.create')
            || $actor->hasPermission('events.update')
            || $actor->hasPermission('terrenos.view')
            || $actor->hasPermission('cabanas.view');
    }
}
