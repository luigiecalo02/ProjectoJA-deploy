<?php

namespace App\Modules\Clubs\Policies;

use App\Models\User;
use App\Modules\Clubs\Models\Club;
use App\Modules\Organizations\Services\OrganizationAccessService;

final class ClubPolicy
{
    public function __construct(private readonly OrganizationAccessService $orgAccess) {}

    public function viewAny(User $actor): bool
    {
        return $actor->hasPermission('clubs.view') || $actor->hasPermission('mi_club.view');
    }

    public function view(User $actor, Club $club): bool
    {
        if (! ($actor->hasPermission('clubs.view') || $actor->hasPermission('mi_club.view'))) {
            return false;
        }

        return $this->orgAccess->canAccessClub($actor, $club);
    }

    public function create(User $actor): bool
    {
        return $actor->hasPermission('clubs.create') || $actor->hasPermission('mi_club.create');
    }

    public function update(User $actor, Club $club): bool
    {
        if (! ($actor->hasPermission('clubs.update') || $actor->hasPermission('mi_club.update'))) {
            return false;
        }

        return $this->orgAccess->canAccessClub($actor, $club);
    }

    public function delete(User $actor, Club $club): bool
    {
        if (! ($actor->hasPermission('clubs.delete') || $actor->hasPermission('mi_club.delete'))) {
            return false;
        }

        return $this->orgAccess->canAccessClub($actor, $club);
    }

    public function manageMembers(User $actor, Club $club): bool
    {
        if (! (
            $actor->hasPermission('clubs.manage_members')
            || $actor->hasPermission('mi_club.manage_members')
            || $actor->hasPermission('clubs.update')
            || $actor->hasPermission('mi_club.update')
        )) {
            return false;
        }

        return $this->orgAccess->canAccessClub($actor, $club);
    }

    public function manageDirectors(User $actor, Club $club): bool
    {
        if (! (
            $actor->hasPermission('clubs.manage_directors')
            || $actor->hasPermission('mi_club.manage_directors')
            || $actor->hasPermission('clubs.update')
            || $actor->hasPermission('mi_club.update')
        )) {
            return false;
        }

        return $this->orgAccess->canAccessClub($actor, $club);
    }
}
