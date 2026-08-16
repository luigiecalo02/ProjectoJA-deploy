<?php

namespace App\Modules\Clubs\Policies;

use App\Models\User;
use App\Modules\Clubs\Models\Persona;
use App\Modules\Clubs\Services\PersonaService;

final class PersonaPolicy
{
    public function __construct(private readonly PersonaService $personaService) {}

    public function viewAny(User $actor): bool
    {
        return $actor->hasPermission('personas.view')
            || $actor->hasPermission('integrantes.view')
            || $actor->hasPermission('clubs.view');
    }

    public function view(User $actor, Persona $persona): bool
    {
        if (! (
            $actor->hasPermission('personas.view')
            || $actor->hasPermission('integrantes.view')
            || $actor->hasPermission('clubs.view')
        )) {
            return false;
        }

        return $this->personaService->actorCanAccess($actor, $persona);
    }

    public function create(User $actor): bool
    {
        return $actor->hasPermission('personas.create')
            || $actor->hasPermission('integrantes.create')
            || $actor->hasPermission('clubs.manage_members')
            || $actor->hasPermission('clubs.create');
    }

    public function update(User $actor, Persona $persona): bool
    {
        if (! (
            $actor->hasPermission('personas.update')
            || $actor->hasPermission('integrantes.update')
            || $actor->hasPermission('clubs.manage_members')
        )) {
            return false;
        }

        return $this->personaService->actorCanAccess($actor, $persona);
    }

    public function delete(User $actor, Persona $persona): bool
    {
        if (! (
            $actor->hasPermission('personas.delete')
            || $actor->hasPermission('integrantes.delete')
        )) {
            return false;
        }

        return $this->personaService->actorCanAccess($actor, $persona);
    }
}
