<?php

namespace App\Modules\Events\Services;

use App\Models\User;
use App\Modules\Cabanas\Models\AsignacionCama;
use App\Modules\Cabanas\Services\ElegibilidadCamaService;
use App\Modules\Events\Models\Event;
use App\Modules\Events\Models\EventoEvidencia;
use App\Modules\Events\Models\EventoInscripcion;
use App\Modules\Organizations\Services\OrganizationAccessService;

/**
 * Enriquece el árbol de la lista de eventos con progreso (juez / director).
 */
final class EventListEnricher
{
    public function __construct(
        private readonly OrganizationAccessService $orgAccess,
        private readonly EventParticipationService $participation,
        private readonly EventJudgeService $judgeService,
        private readonly ElegibilidadCamaService $elegibilidadCama,
    ) {}

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public function enrich(User $actor, Event $rootModel, array $payload): array
    {
        $payload['puede_elegir_cama'] = $actor->hasPermission('cabanas.self_assign')
            && $this->elegibilidadCama->isEligible($actor, $rootModel);
        $payload['mostrar_boton_cama'] = $payload['puede_elegir_cama'];
        $payload['alojamiento_asignado'] = $actor->persona_id
            ? AsignacionCama::query()
                ->where('evento_id', $rootModel->id)
                ->where('estado', AsignacionCama::ESTADO_ACTIVA)
                ->whereHas('inscripcionPersona', fn ($query) => $query->where('persona_id', $actor->persona_id))
                ->exists()
            : false;
        $isJudge = $actor->can('evaluate', $rootModel);
        $directorOrgId = $this->tryDirectorOrgId($actor);

        if (! $isJudge && $directorOrgId === null) {
            return $payload;
        }

        $this->judgeService->ensureTreeLoaded($rootModel, 8);

        // En contexto de director de club: evidencia del club (árbol completo).
        if ($directorOrgId !== null) {
            return $this->enrichForDirector($directorOrgId, $rootModel, $payload);
        }

        return $this->enrichForJudge($actor, $rootModel, $payload);
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function enrichForJudge(User $actor, Event $root, array $payload): array
    {
        $scope = $this->judgeService->scopeForActor($root, $actor);
        $scoreableIds = $this->collectScoreableIds($root, $scope);
        $allowed = $this->judgeAllowedOrgs($actor);

        $pendientes = $this->judgeService->pendientesMapPublic($scoreableIds, $actor);
        $evaluados = $this->judgeService->evaluadosMapPublic($scoreableIds, $actor);

        if ($allowed !== null) {
            $pendientes = $this->filterOrgMap($pendientes, $allowed);
            $evaluados = $this->filterOrgMap($evaluados, $allowed);
        }

        $statsByEvent = [];
        foreach ($scoreableIds as $eid) {
            $calificados = 0;
            $pend = 0;
            foreach ($evaluados as $byEvent) {
                if (isset($byEvent[(string) $eid])) {
                    $calificados++;
                }
            }
            foreach ($pendientes as $byEvent) {
                if (isset($byEvent[(string) $eid])) {
                    $pend++;
                }
            }
            $statsByEvent[$eid] = [
                'calificados' => $calificados,
                'pendientes' => $pend,
                'total' => $calificados + $pend,
            ];
        }

        return $this->mapJudgeTree($payload, $root, $scope, $statsByEvent, true);
    }

    /**
     * @param  array<string, mixed>  $payload
     * @param  array{open: bool, assigned_ids: array<int, true>, visible_ids: array<int, true>}  $scope
     * @param  array<int, array{calificados: int, pendientes: int, total: int}>  $statsByEvent
     * @return array<string, mixed>
     */
    private function mapJudgeTree(
        array $payload,
        Event $root,
        array $scope,
        array $statsByEvent,
        bool $isRoot,
    ): array {
        $id = (int) $payload['id'];
        $node = $id === (int) $root->id ? $root : $this->findNode($root, $id);
        $asignado = $scope['open'] || isset($scope['assigned_ids'][$id]);
        $visible = $scope['open'] || isset($scope['visible_ids'][$id]) || $isRoot;

        $payload['asignado_a_mi'] = $asignado;
        $payload['visible_para_juez'] = $visible;

        if ($node && $this->canScore($node, $scope)) {
            $payload['progreso_juez'] = $statsByEvent[$id] ?? [
                'calificados' => 0,
                'pendientes' => 0,
                'total' => 0,
            ];
        } else {
            $payload['progreso_juez'] = null;
        }

        $children = $payload['hijos'] ?? [];
        if (! is_array($children)) {
            $children = [];
        }

        $mapped = [];
        foreach ($children as $child) {
            if (! is_array($child)) {
                continue;
            }
            $childId = (int) ($child['id'] ?? 0);
            if (! $scope['open'] && ! isset($scope['visible_ids'][$childId]) && ! isset($scope['assigned_ids'][$childId])) {
                continue;
            }
            $mappedChild = $this->mapJudgeTree($child, $root, $scope, $statsByEvent, false);
            // Ocultar ramas vacías no asignadas sin hijos visibles.
            $hasKids = is_array($mappedChild['hijos'] ?? null) && ($mappedChild['hijos'] !== []);
            $childAsignado = ! empty($mappedChild['asignado_a_mi']);
            $childScoreable = ($mappedChild['progreso_juez'] ?? null) !== null;
            if ($scope['open'] || $childAsignado || $hasKids || $childScoreable) {
                $mapped[] = $mappedChild;
            }
        }
        $payload['hijos'] = $mapped;
        $payload['hijos_count'] = count($mapped);

        // Rollup progreso en padres.
        if (($payload['progreso_juez'] ?? null) === null && $mapped !== []) {
            $c = 0;
            $p = 0;
            foreach ($mapped as $m) {
                $prog = $m['progreso_juez'] ?? null;
                if (is_array($prog)) {
                    $c += (int) ($prog['calificados'] ?? 0);
                    $p += (int) ($prog['pendientes'] ?? 0);
                }
            }
            if ($c + $p > 0) {
                $payload['progreso_juez'] = [
                    'calificados' => $c,
                    'pendientes' => $p,
                    'total' => $c + $p,
                ];
            }
        }

        return $payload;
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function enrichForDirector(int $orgId, Event $root, array $payload): array
    {
        $ids = $this->collectEvidenceNodeIds($root);
        $withEvidence = [];
        if ($ids !== []) {
            $rows = EventoEvidencia::query()
                ->whereIn('evento_id', $ids)
                ->where('organizacion_id', $orgId)
                ->selectRaw('evento_id, COUNT(*) as total')
                ->groupBy('evento_id')
                ->pluck('total', 'evento_id');
            foreach ($rows as $eid => $total) {
                if ((int) $total > 0) {
                    $withEvidence[(int) $eid] = true;
                }
            }
        }

        $inscripcion = EventoInscripcion::query()
            ->where('evento_id', $root->id)
            ->where('tipo', 'club')
            ->where('organizacion_id', $orgId)
            ->first();

        $payload['inscripcion_estado'] = $inscripcion?->estado;
        $payload['inscripcion_id'] = $inscripcion?->id;
        $payload['puede_elegir_lote'] = $inscripcion?->estaAprobada() ?? false;

        return $this->mapDirectorTree($payload, $root, $withEvidence);
    }

    /**
     * @param  array<string, mixed>  $payload
     * @param  array<int, true>  $withEvidence
     * @return array<string, mixed>
     */
    private function mapDirectorTree(array $payload, Event $root, array $withEvidence): array
    {
        $id = (int) $payload['id'];
        $node = $id === (int) $root->id ? $root : $this->findNode($root, $id);
        $requires = (bool) ($payload['requiere_evidencia'] ?? false)
            || (bool) ($node?->requiere_evidencia);

        if ($requires && $node && $node->es_calificable && ! $node->puntaje_desde_hijos) {
            $payload['evidencia_enviada'] = isset($withEvidence[$id]);
        } else {
            $payload['evidencia_enviada'] = null;
        }

        $children = $payload['hijos'] ?? [];
        if (! is_array($children)) {
            $children = [];
        }

        $mapped = [];
        $con = 0;
        $sin = 0;
        foreach ($children as $child) {
            if (! is_array($child)) {
                continue;
            }
            $mappedChild = $this->mapDirectorTree($child, $root, $withEvidence);
            $mapped[] = $mappedChild;
            if ($mappedChild['evidencia_enviada'] === true) {
                $con++;
            } elseif ($mappedChild['evidencia_enviada'] === false) {
                $sin++;
            }
            $childProg = $mappedChild['progreso_evidencia'] ?? null;
            if (is_array($childProg)) {
                $con += (int) ($childProg['con_evidencia'] ?? 0);
                $sin += (int) ($childProg['sin_evidencia'] ?? 0);
            }
        }
        $payload['hijos'] = $mapped;
        $payload['hijos_count'] = count($mapped);

        if ($con + $sin > 0) {
            $payload['progreso_evidencia'] = [
                'con_evidencia' => $con,
                'sin_evidencia' => $sin,
                'total' => $con + $sin,
            ];
        } else {
            $payload['progreso_evidencia'] = null;
        }

        return $payload;
    }

    private function tryDirectorOrgId(User $actor): ?int
    {
        try {
            return (int) $this->participation->assertClubDirectorContext($actor)['organizacion_id'];
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * @return array<int, true>|null
     */
    private function judgeAllowedOrgs(User $actor): ?array
    {
        if ($this->orgAccess->bypassesOrganizationScope($actor)) {
            return null;
        }

        return array_fill_keys($this->orgAccess->accessibleOrganizationIds($actor), true);
    }

    /**
     * @param  array<string, array<string, mixed>>  $map
     * @param  array<int, true>  $allowed
     * @return array<string, array<string, mixed>>
     */
    private function filterOrgMap(array $map, array $allowed): array
    {
        $out = [];
        foreach ($map as $orgKey => $value) {
            if (isset($allowed[(int) $orgKey])) {
                $out[(string) $orgKey] = $value;
            }
        }

        return $out;
    }

    /**
     * @param  array{open: bool, assigned_ids: array<int, true>, visible_ids: array<int, true>}  $scope
     * @return list<int>
     */
    private function collectScoreableIds(Event $node, array $scope): array
    {
        $ids = [];
        $walk = function (Event $n) use (&$walk, &$ids, $scope): void {
            if ($this->canScore($n, $scope)) {
                $ids[] = (int) $n->id;
            }
            foreach ($n->hijos ?? [] as $hijo) {
                $walk($hijo);
            }
        };
        $walk($node);

        return array_values(array_unique($ids));
    }

    /**
     * @return list<int>
     */
    private function collectEvidenceNodeIds(Event $node): array
    {
        $ids = [];
        $walk = function (Event $n) use (&$walk, &$ids): void {
            if ($n->requiere_evidencia && $n->es_calificable && ! $n->puntaje_desde_hijos && $n->evento_padre_id) {
                $ids[] = (int) $n->id;
            }
            foreach ($n->hijos ?? [] as $hijo) {
                $walk($hijo);
            }
        };
        $walk($node);

        return array_values(array_unique($ids));
    }

    /**
     * @param  array{open: bool, assigned_ids: array<int, true>, visible_ids: array<int, true>}  $scope
     */
    private function canScore(Event $node, array $scope): bool
    {
        if (! $node->es_calificable || $node->puntaje_desde_hijos) {
            return false;
        }
        if ($scope['open']) {
            return true;
        }

        return isset($scope['assigned_ids'][(int) $node->id]);
    }

    private function findNode(Event $root, int $id): ?Event
    {
        if ((int) $root->id === $id) {
            return $root;
        }
        foreach ($root->hijos ?? [] as $hijo) {
            $found = $this->findNode($hijo, $id);
            if ($found) {
                return $found;
            }
        }

        return null;
    }
}
