<?php

namespace App\Modules\Events\Services;

use App\Models\User;
use App\Modules\Clubs\Models\Club;
use App\Modules\Events\Models\Event;
use App\Modules\Organizations\Models\Organizacion;
use App\Modules\Organizations\Models\TipoOrganizacion;
use App\Modules\Organizations\Services\OrganizationAccessService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

final class EventVisibilityService
{
    public function __construct(
        private readonly OrganizationAccessService $organizationAccess,
        private readonly EventAudienceMatcher $audienceMatcher,
    ) {}

    public function isVisibleTo(Event $event, User $actor): bool
    {
        if ($this->organizationAccess->bypassesOrganizationScope($actor)) {
            return true;
        }

        if ($event->visibilidad === Event::VISIBILIDAD_PUBLICO) {
            return true;
        }

        if (! $this->isWithinOrganizationReach($event, $actor)
            || ! $this->matchesAudience($event, $actor)) {
            return false;
        }

        if ($event->visibilidad !== Event::VISIBILIDAD_PRIVADO) {
            return true;
        }

        [$jueces] = $event->resolveEffectiveJueces();
        [$supervisores] = $event->resolveEffectiveSupervisores();

        return $jueces->contains('id', $actor->id)
            || $supervisores->contains('id', $actor->id)
            || $this->actorAssignedInDescendants($event, $actor);
    }

    public function applyVisibleScope(Builder $query, User $actor): Builder
    {
        if ($this->organizationAccess->bypassesOrganizationScope($actor)) {
            return $query;
        }

        $organizationIds = $this->visibilityOrganizationIdsForActor($actor);
        $audience = $this->resolveAudienceScope($actor);
        $privateEventIds = $this->privateEventIdsForActor($actor);

        return $query->where(function (Builder $visibility) use (
            $organizationIds,
            $audience,
            $privateEventIds
        ) {
            $visibility->where('visibilidad', Event::VISIBILIDAD_PUBLICO);

            if ($organizationIds === [] || ! $audience['allowed']) {
                return;
            }

            $visibility->orWhere(function (Builder $organization) use ($organizationIds, $audience) {
                $organization->where('visibilidad', Event::VISIBILIDAD_ORGANIZACION);
                $this->applyOrganizationAndAudienceScope($organization, $organizationIds, $audience);
            });

            if ($privateEventIds !== []) {
                $visibility->orWhere(function (Builder $private) use (
                    $organizationIds,
                    $audience,
                    $privateEventIds
                ) {
                    $private
                        ->where('visibilidad', Event::VISIBILIDAD_PRIVADO)
                        ->whereIn('events.id', $privateEventIds);
                    $this->applyOrganizationAndAudienceScope($private, $organizationIds, $audience);
                });
            }
        });
    }

    private function isWithinOrganizationReach(Event $event, User $actor): bool
    {
        $organizationIds = $this->visibilityOrganizationIdsForActor($actor);
        if ($organizationIds === []) {
            return false;
        }

        if ($event->organizacion_id
            && in_array((int) $event->organizacion_id, $organizationIds, true)) {
            return true;
        }

        return $event->organizaciones()
            ->whereIn('organizacion.id', $organizationIds)
            ->exists();
    }

    private function matchesAudience(Event $event, User $actor): bool
    {
        $typeIds = $event->relationLoaded('tiposOrganizacion')
            ? $event->tiposOrganizacion->pluck('id')->map(fn ($id) => (int) $id)->all()
            : $event->tiposOrganizacion()->pluck('tipo_organizacion.id')->map(fn ($id) => (int) $id)->all();

        return $this->audienceMatcher->actorMatchesAudience($actor, $typeIds);
    }

    /**
     * @param  list<int>  $organizationIds
     * @param  array{allowed: bool, unrestricted: bool, type_ids: list<int>}  $audience
     */
    private function applyOrganizationAndAudienceScope(
        Builder $query,
        array $organizationIds,
        array $audience
    ): void {
        $query->where(function (Builder $organizations) use ($organizationIds) {
            $organizations->whereIn('organizacion_id', $organizationIds)
                ->orWhereHas(
                    'organizaciones',
                    fn (Builder $related) => $related->whereIn('organizacion.id', $organizationIds)
                );
        });

        if ($audience['unrestricted']) {
            return;
        }

        $query->where(function (Builder $types) use ($audience) {
            $types->whereDoesntHave('tiposOrganizacion');
            if ($audience['type_ids'] !== []) {
                $types->orWhereHas(
                    'tiposOrganizacion',
                    fn (Builder $related) => $related->whereIn(
                        'tipo_organizacion.id',
                        $audience['type_ids']
                    )
                );
            }
        });
    }

    /**
     * @return array{allowed: bool, unrestricted: bool, type_ids: list<int>}
     */
    private function resolveAudienceScope(User $actor): array
    {
        $membershipIds = $this->organizationAccess->membershipOrganizationIds($actor);
        if ($membershipIds === []) {
            return ['allowed' => false, 'unrestricted' => false, 'type_ids' => []];
        }

        $organizations = Organizacion::query()
            ->whereIn('id', $membershipIds)
            ->get(['id', 'tipo_organizacion_id']);

        $clubOrganizationIds = [];
        foreach ($organizations as $organization) {
            $typeId = (int) $organization->tipo_organizacion_id;
            if (! in_array($typeId, [
                Organizacion::TIPO_CLUB,
                Organizacion::TIPO_AVENTUREROS,
                Organizacion::TIPO_CONQUISTADORES,
                Organizacion::TIPO_GUIAS_MAYORES,
            ], true)) {
                return ['allowed' => true, 'unrestricted' => true, 'type_ids' => []];
            }
            $clubOrganizationIds[] = (int) $organization->id;
        }

        $actorTypeIds = $organizations
            ->pluck('tipo_organizacion_id')
            ->map(fn ($id) => (int) $id)
            ->filter(fn (int $id) => in_array($id, [
                Organizacion::TIPO_AVENTUREROS,
                Organizacion::TIPO_CONQUISTADORES,
                Organizacion::TIPO_GUIAS_MAYORES,
            ], true))
            ->values()
            ->all();

        $ministries = Club::query()
            ->whereIn('organizacion_id', $clubOrganizationIds)
            ->get(['tipos'])
            ->flatMap(fn (Club $club) => (array) ($club->tipos ?? []))
            ->map(fn ($ministry) => (string) $ministry)
            ->unique()
            ->values()
            ->all();

        $catalogTypes = TipoOrganizacion::query()
            ->where(function (Builder $types) {
                $types->where('nombre', 'like', '%Aventurer%')
                    ->orWhere('nombre', 'like', '%Conquistador%')
                    ->orWhere('nombre', 'like', '%Gu%a%Mayor%')
                    ->orWhere('nombre', 'like', '%Guia%Mayor%');
            })
            ->get(['id']);

        foreach ($catalogTypes as $type) {
            $typeMinistries = $this->audienceMatcher->ministriesForTipoIds([(int) $type->id]);
            if (array_intersect($typeMinistries, $ministries) !== []) {
                $actorTypeIds[] = (int) $type->id;
            }
        }

        return [
            'allowed' => true,
            'unrestricted' => false,
            'type_ids' => array_values(array_unique($actorTypeIds)),
        ];
    }

    /**
     * @return list<int>
     */
    private function visibilityOrganizationIdsForActor(User $actor): array
    {
        $accessible = $this->organizationAccess->accessibleOrganizationIds($actor);
        if ($accessible === []) {
            return [];
        }

        $ids = $accessible;
        foreach ($accessible as $organizationId) {
            $ids = array_merge($ids, $this->ancestorOrganizationIds((int) $organizationId));
        }

        return array_values(array_unique(array_map('intval', $ids)));
    }

    /**
     * @return list<int>
     */
    private function ancestorOrganizationIds(int $organizationId): array
    {
        $ids = [];
        $current = $organizationId;
        $guard = 0;

        while ($guard < 32) {
            $parentId = Organizacion::query()
                ->where('id', $current)
                ->value('organizacion_padre_id');
            if (! $parentId) {
                break;
            }
            $ids[] = (int) $parentId;
            $current = (int) $parentId;
            $guard++;
        }

        return $ids;
    }

    /**
     * @return list<int>
     */
    private function privateEventIdsForActor(User $actor): array
    {
        $events = Event::query()->get(['id', 'evento_padre_id', 'visibilidad'])->keyBy('id');
        $judgeAssignments = DB::table('evento_juez')->get(['evento_id', 'user_id'])->groupBy('evento_id');
        $supervisorAssignments = DB::table('evento_supervisor')->get(['evento_id', 'user_id'])->groupBy('evento_id');
        $childrenByParent = $events
            ->filter(fn (Event $event) => $event->evento_padre_id !== null)
            ->groupBy(fn (Event $event) => (int) $event->evento_padre_id);
        $actorAssignedEventIds = DB::table('evento_juez')
            ->where('user_id', $actor->id)
            ->pluck('evento_id')
            ->merge(
                DB::table('evento_supervisor')
                    ->where('user_id', $actor->id)
                    ->pluck('evento_id')
            )
            ->map(fn ($id) => (int) $id)
            ->unique()
            ->values()
            ->all();

        return $events
            ->filter(fn (Event $event) => $event->visibilidad === Event::VISIBILIDAD_PRIVADO)
            ->filter(function (Event $event) use (
                $events,
                $judgeAssignments,
                $supervisorAssignments,
                $childrenByParent,
                $actorAssignedEventIds,
                $actor
            ) {
                return $this->hasEffectiveAssignment(
                    $event,
                    $events,
                    $judgeAssignments,
                    (int) $actor->id
                ) || $this->hasEffectiveAssignment(
                    $event,
                    $events,
                    $supervisorAssignments,
                    (int) $actor->id
                ) || $this->descendantsContainAssignment(
                    (int) $event->id,
                    $childrenByParent,
                    $actorAssignedEventIds
                );
            })
            ->keys()
            ->map(fn ($id) => (int) $id)
            ->values()
            ->all();
    }

    private function hasEffectiveAssignment(
        Event $event,
        $events,
        $assignments,
        int $actorId
    ): bool {
        $current = $event;
        $guard = 0;

        while ($guard < 32) {
            $currentAssignments = $assignments->get($current->id, collect());
            if ($currentAssignments->isNotEmpty()) {
                return $currentAssignments->contains(
                    fn ($assignment) => (int) $assignment->user_id === $actorId
                );
            }
            if (! $current->evento_padre_id) {
                return false;
            }
            $current = $events->get((int) $current->evento_padre_id);
            if (! $current) {
                return false;
            }
            $guard++;
        }

        return false;
    }

    private function actorAssignedInDescendants(Event $event, User $actor): bool
    {
        $descendantIds = [];
        $pendingIds = [(int) $event->id];
        $guard = 0;

        while ($pendingIds !== [] && $guard < 32) {
            $childIds = Event::query()
                ->whereIn('evento_padre_id', $pendingIds)
                ->pluck('id')
                ->map(fn ($id) => (int) $id)
                ->all();
            $descendantIds = array_merge($descendantIds, $childIds);
            $pendingIds = $childIds;
            $guard++;
        }

        if ($descendantIds === []) {
            return false;
        }

        return DB::table('evento_juez')
            ->where('user_id', $actor->id)
            ->whereIn('evento_id', $descendantIds)
            ->exists()
            || DB::table('evento_supervisor')
                ->where('user_id', $actor->id)
                ->whereIn('evento_id', $descendantIds)
                ->exists();
    }

    private function descendantsContainAssignment(
        int $eventId,
        $childrenByParent,
        array $actorAssignedEventIds
    ): bool {
        $pendingIds = [$eventId];
        $guard = 0;

        while ($pendingIds !== [] && $guard < 32) {
            $childIds = collect($pendingIds)
                ->flatMap(fn (int $parentId) => $childrenByParent->get($parentId, collect())->pluck('id'))
                ->map(fn ($id) => (int) $id)
                ->all();
            if (array_intersect($childIds, $actorAssignedEventIds) !== []) {
                return true;
            }
            $pendingIds = $childIds;
            $guard++;
        }

        return false;
    }
}
