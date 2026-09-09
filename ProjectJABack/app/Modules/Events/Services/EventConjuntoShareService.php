<?php

namespace App\Modules\Events\Services;

use App\Modules\Clubs\Models\Club;
use App\Modules\Events\Models\Event;
use App\Modules\Events\Models\EventoActividadParticipante;
use App\Modules\Events\Models\EventoCalificacion;
use App\Modules\Events\Models\EventoCalificacionDetalle;
use App\Modules\Events\Models\EventoInscripcion;
use App\Modules\Organizations\Models\Organizacion;
use App\Modules\Organizations\Services\OrganizationAccessService;

/**
 * Comparte roster y puntaje de actividades en conjunto
 * solo entre clubes inscritos del mismo alcance y audiencia.
 */
final class EventConjuntoShareService
{
    private const CLUB_TIPOS = [
        Organizacion::TIPO_CLUB,
        Organizacion::TIPO_AVENTUREROS,
        Organizacion::TIPO_CONQUISTADORES,
        Organizacion::TIPO_GUIAS_MAYORES,
    ];

    public function __construct(
        private readonly OrganizationAccessService $orgAccess,
        private readonly EventAudienceMatcher $audienceMatcher,
    ) {}

    /**
     * @return list<int>
     */
    public function scopeOrganizationIds(Event $actividad, int $organizacionId): array
    {
        if (! $actividad->es_conjunto) {
            return [$organizacionId];
        }

        $nivel = is_string($actividad->nivel_conjunto) && $actividad->nivel_conjunto !== ''
            ? $actividad->nivel_conjunto
            : 'club';
        $anchorId = $this->ancestorIdAtNivel($organizacionId, $nivel);
        if ($anchorId === null) {
            return [];
        }

        return array_values(array_unique([
            $anchorId,
            ...$this->orgAccess->descendantIds($anchorId),
        ]));
    }

    public function normalizedRol(mixed $rol): ?string
    {
        $value = is_string($rol) ? trim($rol) : '';
        if ($value === '' || ! in_array($value, Event::ROLES_CONJUNTO, true)) {
            return null;
        }

        return $value;
    }

    /**
     * Clubes inscritos en el evento raíz, del mismo conjunto y de la audiencia.
     *
     * @return list<int>
     */
    public function peerClubOrganizationIds(Event $root, Event $actividad, int $sourceOrgId): array
    {
        if (! $actividad->es_conjunto) {
            return [$sourceOrgId];
        }

        $scopeIds = $this->scopeOrganizationIds($actividad, $sourceOrgId);
        if ($scopeIds === []) {
            return [$sourceOrgId];
        }

        $participating = $this->participatingClubOrganizationIds($root);
        $candidates = array_values(array_intersect($scopeIds, $participating));
        if (! in_array($sourceOrgId, $candidates, true)) {
            $candidates[] = $sourceOrgId;
        }

        $clubOrgIds = $this->clubOrganizationIdsIn($candidates);
        $clubOrgIds = $this->filterByEventAudience($root, $clubOrgIds);
        if (! in_array($sourceOrgId, $clubOrgIds, true)) {
            $clubOrgIds[] = $sourceOrgId;
        }

        return array_values(array_unique(array_map('intval', $clubOrgIds)));
    }

    /**
     * @param  list<int>  $personaIds
     */
    public function syncRoster(Event $root, Event $actividad, array $personaIds, int $sourceOrgId, int $actorId): void
    {
        $personaIds = array_values(array_unique(array_map('intval', $personaIds)));
        $peerIds = $this->peerClubOrganizationIds($root, $actividad, $sourceOrgId);

        foreach ($peerIds as $orgId) {
            EventoActividadParticipante::query()
                ->where('evento_id', $actividad->id)
                ->where('organizacion_id', $orgId)
                ->whereNotIn('persona_id', $personaIds === [] ? [0] : $personaIds)
                ->delete();

            foreach ($personaIds as $personaId) {
                EventoActividadParticipante::query()->updateOrCreate(
                    [
                        'evento_id' => $actividad->id,
                        'organizacion_id' => $orgId,
                        'persona_id' => $personaId,
                    ],
                    ['inscrito_por' => $actorId],
                );
            }
        }
    }

    public function inheritForClub(Event $root, int $organizacionId, ?int $actorId = null): void
    {
        $actividadIds = $this->conjuntoActivityIds($root);
        if ($actividadIds === []) {
            return;
        }

        $activities = Event::query()
            ->whereIn('id', $actividadIds)
            ->get(['id', 'es_conjunto', 'nivel_conjunto', 'rol_conjunto']);

        foreach ($activities as $actividad) {
            $peerIds = $this->peerClubOrganizationIds($root, $actividad, $organizacionId);
            if (! in_array($organizacionId, $peerIds, true)) {
                continue;
            }

            $already = EventoActividadParticipante::query()
                ->where('evento_id', $actividad->id)
                ->where('organizacion_id', $organizacionId)
                ->exists();
            if ($already) {
                continue;
            }

            $personaIds = EventoActividadParticipante::query()
                ->where('evento_id', $actividad->id)
                ->whereIn('organizacion_id', $peerIds === [] ? [0] : $peerIds)
                ->pluck('persona_id')
                ->map(fn ($id) => (int) $id)
                ->unique()
                ->values()
                ->all();
            if ($personaIds === []) {
                continue;
            }

            foreach ($personaIds as $personaId) {
                EventoActividadParticipante::query()->updateOrCreate(
                    [
                        'evento_id' => $actividad->id,
                        'organizacion_id' => $organizacionId,
                        'persona_id' => $personaId,
                    ],
                    ['inscrito_por' => $actorId],
                );
            }
        }
    }

    /**
     * @param  list<array{criterio_evaluacion_id: int, puntos: float|int}>  $detalles
     */
    public function replicateScore(
        Event $root,
        Event $actividad,
        EventoCalificacion $source,
        array $detalles,
        int $sourceOrgId,
    ): void {
        if (! $actividad->es_conjunto) {
            return;
        }

        $peerIds = $this->peerClubOrganizationIds($root, $actividad, $sourceOrgId);
        foreach ($peerIds as $orgId) {
            if ($orgId === $sourceOrgId) {
                continue;
            }

            $copy = EventoCalificacion::query()->updateOrCreate(
                [
                    'evento_id' => $actividad->id,
                    'organizacion_id' => $orgId,
                    'persona_id' => null,
                    'calificado_por' => $source->calificado_por,
                ],
                [
                    'puntaje_obtenido' => $source->puntaje_obtenido,
                    'observaciones' => $source->observaciones,
                    'puesto_entrega' => $source->puesto_entrega,
                    'tiempo_entrega' => $source->tiempo_entrega,
                    'resultado_obtenido' => $source->resultado_obtenido,
                    'permite_editar_evidencia' => (bool) $source->permite_editar_evidencia,
                ],
            );

            EventoCalificacionDetalle::query()
                ->where('calificacion_id', $copy->id)
                ->delete();

            foreach ($detalles as $row) {
                EventoCalificacionDetalle::query()->create([
                    'calificacion_id' => $copy->id,
                    'criterio_evaluacion_id' => $row['criterio_evaluacion_id'],
                    'puntos' => $row['puntos'],
                ]);
            }
        }
    }

    /**
     * @return list<int>
     */
    private function participatingClubOrganizationIds(Event $root): array
    {
        return EventoInscripcion::query()
            ->where('evento_id', $root->id)
            ->where('tipo', EventoInscripcion::TIPO_CLUB)
            ->whereNotIn('estado', [
                EventoInscripcion::ESTADO_BORRADOR,
                EventoInscripcion::ESTADO_NO_APROBADA,
            ])
            ->pluck('organizacion_id')
            ->map(fn ($id) => (int) $id)
            ->unique()
            ->values()
            ->all();
    }

    /**
     * @param  list<int>  $organizationIds
     * @return list<int>
     */
    private function clubOrganizationIdsIn(array $organizationIds): array
    {
        if ($organizationIds === []) {
            return [];
        }

        return Organizacion::query()
            ->whereIn('id', $organizationIds)
            ->whereIn('tipo_organizacion_id', self::CLUB_TIPOS)
            ->pluck('id')
            ->map(fn ($id) => (int) $id)
            ->values()
            ->all();
    }

    /**
     * @param  list<int>  $clubOrgIds
     * @return list<int>
     */
    private function filterByEventAudience(Event $root, array $clubOrgIds): array
    {
        if ($clubOrgIds === []) {
            return [];
        }

        $tipoIds = $root->relationLoaded('tiposOrganizacion')
            ? $root->tiposOrganizacion->pluck('id')->map(fn ($id) => (int) $id)->all()
            : $root->tiposOrganizacion()->pluck('tipo_organizacion.id')->map(fn ($id) => (int) $id)->all();
        $allowed = $this->audienceMatcher->ministriesForTipoIds($tipoIds);
        if ($allowed === []) {
            return $clubOrgIds;
        }

        $matching = Club::query()
            ->whereIn('organizacion_id', $clubOrgIds)
            ->get(['organizacion_id', 'tipos'])
            ->filter(function (Club $club) use ($allowed) {
                $tipos = is_array($club->tipos) ? $club->tipos : [];

                return count(array_intersect($tipos, $allowed)) > 0;
            })
            ->pluck('organizacion_id')
            ->map(fn ($id) => (int) $id)
            ->values()
            ->all();

        return array_values(array_unique($matching));
    }

    /**
     * @return list<int>
     */
    private function conjuntoActivityIds(Event $root): array
    {
        $ids = array_values(array_unique([
            (int) $root->id,
            ...$this->descendantEventIds((int) $root->id),
        ]));

        return Event::query()
            ->whereIn('id', $ids === [] ? [0] : $ids)
            ->where('es_conjunto', true)
            ->pluck('id')
            ->map(fn ($id) => (int) $id)
            ->values()
            ->all();
    }

    /**
     * @return list<int>
     */
    private function descendantEventIds(int $rootId): array
    {
        $ids = [];
        $frontier = [$rootId];

        while ($frontier !== []) {
            $children = Event::query()
                ->whereIn('evento_padre_id', $frontier)
                ->pluck('id')
                ->all();
            $new = array_values(array_diff($children, $ids));
            if ($new === []) {
                break;
            }
            $ids = array_merge($ids, $new);
            $frontier = $new;
        }

        return array_map('intval', $ids);
    }

    private function ancestorIdAtNivel(int $organizacionId, string $nivel): ?int
    {
        $tipos = match ($nivel) {
            'club' => self::CLUB_TIPOS,
            'iglesia' => [Organizacion::TIPO_IGLESIA],
            'distrito' => [Organizacion::TIPO_DISTRITO],
            'zona' => [Organizacion::TIPO_ZONA],
            'asociacion' => [Organizacion::TIPO_ASOCIACION],
            default => [],
        };
        if ($tipos === []) {
            return null;
        }

        $current = Organizacion::query()->find(
            $organizacionId,
            ['id', 'tipo_organizacion_id', 'organizacion_padre_id']
        );
        $guard = 0;

        while ($current && $guard < 16) {
            if (in_array((int) $current->tipo_organizacion_id, $tipos, true)) {
                return (int) $current->id;
            }

            $parentId = $current->organizacion_padre_id ? (int) $current->organizacion_padre_id : 0;
            if ($parentId <= 0) {
                return null;
            }

            $current = Organizacion::query()->find(
                $parentId,
                ['id', 'tipo_organizacion_id', 'organizacion_padre_id']
            );
            $guard++;
        }

        return null;
    }
}
