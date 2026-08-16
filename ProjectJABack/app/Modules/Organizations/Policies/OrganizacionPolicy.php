<?php

namespace App\Modules\Organizations\Policies;

use App\Models\User;
use App\Modules\Organizations\Models\Organizacion;
use App\Modules\Organizations\Services\OrganizationAccessService;

final class OrganizacionPolicy
{
    public function __construct(private readonly OrganizationAccessService $orgAccess) {}

    public function viewAny(User $actor): bool
    {
        return $actor->hasPermission('organizaciones.view');
    }

    public function view(User $actor, Organizacion $organizacion): bool
    {
        if (! $actor->hasPermission('organizaciones.view')) {
            return false;
        }

        return $this->orgAccess->canAccessOrganization($actor, (int) $organizacion->id);
    }

    public function create(User $actor): bool
    {
        return $actor->hasPermission('organizaciones.create');
    }

    public function update(User $actor, Organizacion $organizacion): bool
    {
        if (! $actor->hasPermission('organizaciones.update')) {
            return false;
        }

        return $this->orgAccess->canAccessOrganization($actor, (int) $organizacion->id);
    }

    public function delete(User $actor, Organizacion $organizacion): bool
    {
        if (! $actor->hasPermission('organizaciones.delete')) {
            return false;
        }

        return $this->orgAccess->canAccessOrganization($actor, (int) $organizacion->id);
    }
}
