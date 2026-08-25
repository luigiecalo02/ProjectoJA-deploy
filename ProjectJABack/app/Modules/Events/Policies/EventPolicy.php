<?php

namespace App\Modules\Events\Policies;

use App\Models\User;
use App\Modules\Events\Models\Event;
use App\Modules\Organizations\Services\OrganizationAccessService;
use Illuminate\Support\Collection;

final class EventPolicy
{
    public function __construct(private readonly OrganizationAccessService $orgAccess) {}

    public function viewAny(User $actor): bool
    {
        return $actor->hasPermission('events.view');
    }

    public function view(User $actor, Event $event): bool
    {
        if ($this->isCreator($actor, $event) && (
            $actor->hasPermission('events.view') || $actor->hasPermission('events.create')
        )) {
            return true;
        }

        if (! $actor->hasPermission('events.view')) {
            return false;
        }

        if ($this->orgAccess->bypassesOrganizationScope($actor)) {
            return true;
        }

        return $event->isVisibleTo($actor);
    }

    public function create(User $actor): bool
    {
        return $actor->hasPermission('events.create');
    }

    public function update(User $actor, Event $event): bool
    {
        if ($this->isCreator($actor, $event) && (
            $actor->hasPermission('events.update') || $actor->hasPermission('events.create')
        )) {
            return true;
        }

        if (! $actor->hasPermission('events.update')) {
            return false;
        }

        if ($this->orgAccess->bypassesOrganizationScope($actor)) {
            return true;
        }

        return $event->isVisibleTo($actor);
    }

    public function delete(User $actor, Event $event): bool
    {
        if (! $actor->hasPermission('events.delete')) {
            return false;
        }

        if ($this->orgAccess->bypassesOrganizationScope($actor)) {
            return true;
        }

        return $event->isVisibleTo($actor);
    }

    public function evaluate(User $actor, Event $event): bool
    {
        if (! $actor->hasPermission('events.evaluate')) {
            return false;
        }

        if ($this->orgAccess->bypassesOrganizationScope($actor)) {
            return true;
        }

        if (! $event->isVisibleTo($actor)) {
            return false;
        }

        $root = $event;
        $guard = 0;
        while ($root->evento_padre_id && $guard < 20) {
            $root = $root->padre ?? Event::query()->find($root->evento_padre_id);
            if (! $root) {
                break;
            }
            $guard++;
        }

        if (! $root) {
            return false;
        }

        // Cargar árbol liviano para revisar asignaciones de jueces.
        $this->eagerLoadJuecesTree($root, 8);

        $hasAnyJuez = $this->subtreeHasAnyJuez($root);
        if (! $hasAnyJuez) {
            // Sin jueces asignados en el árbol: cualquiera con permiso puede evaluar.
            return true;
        }

        return $this->actorAssignedInSubtree($root, $actor);
    }

    private function isCreator(User $actor, Event $event): bool
    {
        return $event->created_by !== null && (int) $event->created_by === (int) $actor->id;
    }

    private function eagerLoadJuecesTree(Event $event, int $depth): void
    {
        if ($depth <= 0) {
            return;
        }

        $event->loadMissing([
            'jueces:id',
            'hijos' => fn ($q) => $q->orderBy('orden')->orderBy('id'),
        ]);

        foreach ($event->hijos as $hijo) {
            $this->eagerLoadJuecesTree($hijo, $depth - 1);
        }
    }

    private function subtreeHasAnyJuez(Event $node): bool
    {
        if ($node->ownJueces()->isNotEmpty()) {
            return true;
        }
        foreach ($node->hijos ?? [] as $hijo) {
            if ($this->subtreeHasAnyJuez($hijo)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  Collection<int, User>|null  $inheritedJueces
     */
    private function actorAssignedInSubtree(Event $node, User $actor, $inheritedJueces = null): bool
    {
        [$effective] = $node->resolveEffectiveJueces($inheritedJueces);

        if ($effective->contains(fn ($u) => (int) $u->id === (int) $actor->id)) {
            return true;
        }

        $own = $node->ownJueces();
        $passDown = $own->isNotEmpty() ? $own : $effective;

        foreach ($node->hijos ?? [] as $hijo) {
            if ($this->actorAssignedInSubtree($hijo, $actor, $passDown)) {
                return true;
            }
        }

        return false;
    }

    public function viewScores(User $actor, Event $event): bool
    {
        if (! $actor->hasPermission('events.view_scores') && ! $actor->hasPermission('events.evaluate')) {
            return false;
        }

        if ($this->orgAccess->bypassesOrganizationScope($actor)) {
            return true;
        }

        return $event->isVisibleTo($actor);
    }
}
