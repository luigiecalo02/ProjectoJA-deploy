<?php

namespace App\Modules\Events\Services;

use App\Models\User;
use App\Modules\Clubs\Models\Club;
use App\Modules\Events\Models\Event;
use App\Modules\Events\Models\EventoCalificacion;
use App\Modules\Events\Models\EventoInscripcion;
use App\Modules\Organizations\Models\Organizacion;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

/**
 * Ranking / tabla de puntajes por club.
 */
final class EventStandingsService
{
    public function __construct(
        private readonly EventParticipationService $participation,
        private readonly EventCalificacionAggregator $calificacionAggregator,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function standings(
        User $actor,
        Event $event,
        ?int $subeventoId = null,
        string $sort = 'puesto',
        ?string $q = null,
    ): array {
        $root = $this->participation->resolveRoot($event);

        if (! $actor->can('viewScores', $root)) {
            throw new AccessDeniedHttpException('No puedes ver los puntajes de este evento.');
        }

        $this->eagerLoadTree($root, 8);

        $scope = $root;
        if ($subeventoId) {
            $found = $this->findInTree($root, $subeventoId);
            if (! $found) {
                throw ValidationException::withMessages([
                    'subevento_id' => ['El subevento no pertenece a este evento.'],
                ]);
            }
            $scope = $found;
        }

        $sort = in_array($sort, ['puesto', 'nombre', 'distrito', 'puntaje'], true) ? $sort : 'puesto';
        $isRootScope = (int) $scope->id === (int) $root->id;
        $leafIds = $this->collectLeafScoreIds($scope);
        $maxSub = $this->leafMaxTotal($scope, $leafIds);

        $inscritos = EventoInscripcion::query()
            ->where('evento_id', $root->id)
            ->where('tipo', 'club')
            ->whereNotNull('organizacion_id')
            ->pluck('organizacion_id')
            ->map(fn ($id) => (int) $id)
            ->unique()
            ->values()
            ->all();

        $orgIdsFromScores = [];
        if ($leafIds !== []) {
            $orgIdsFromScores = EventoCalificacion::query()
                ->whereIn('evento_id', $leafIds)
                ->whereNotNull('organizacion_id')
                ->whereNull('persona_id')
                ->pluck('organizacion_id')
                ->map(fn ($id) => (int) $id)
                ->unique()
                ->values()
                ->all();
        }

        $orgIds = array_values(array_unique(array_merge($inscritos, $orgIdsFromScores)));
        if ($orgIds === []) {
            return $this->emptyPayload($root, $scope, $isRootScope, $sort, $maxSub);
        }

        $orgs = Organizacion::query()
            ->whereIn('id', $orgIds)
            ->with(['padre.padre'])
            ->get(['id', 'nombre', 'organizacion_padre_id', 'tipo_organizacion_id'])
            ->keyBy('id');

        $clubs = Club::query()
            ->whereIn('organizacion_id', $orgIds)
            ->get(['id', 'organizacion_id', 'nombre', 'logo', 'distrito'])
            ->keyBy('organizacion_id');

        $scoresByOrg = $this->calificacionAggregator->averagedTotalsByOrg($leafIds, $orgIds);

        $inscripcionByOrg = collect();
        if ($isRootScope) {
            $inscripcionByOrg = EventoCalificacion::query()
                ->where('evento_id', $root->id)
                ->whereIn('organizacion_id', $orgIds)
                ->whereNull('persona_id')
                ->whereNull('calificado_por')
                ->get()
                ->keyBy(fn (EventoCalificacion $c) => (int) $c->organizacion_id);
        }

        $rows = [];
        foreach ($orgIds as $orgId) {
            $org = $orgs->get($orgId);
            if (! $org) {
                continue;
            }
            $club = $clubs->get($orgId);
            $district = $this->resolveDistrict($org, $club);
            $subPts = (float) ($scoresByOrg[$orgId] ?? 0);
            $insPts = $isRootScope
                ? (float) ($inscripcionByOrg->get($orgId)?->puntaje_obtenido ?? 0)
                : null;
            $total = $isRootScope ? (($insPts ?? 0) + $subPts) : $subPts;
            $maxTotal = $isRootScope
                ? $maxSub + max(
                    (float) ($root->puntos_inscripcion_a_tiempo ?? 0),
                    (float) ($root->puntos_inscripcion_fuera_tiempo ?? 0),
                )
                : $maxSub;
            $pct = $maxTotal > 0 ? (int) round(($total / $maxTotal) * 100) : null;

            $rows[] = [
                'organizacion_id' => $orgId,
                'club_id' => $club?->id,
                'nombre' => $club?->nombre ?: $org->nombre,
                'logo_url' => $club?->logo ?: null,
                'distrito' => $district['nombre'],
                'distrito_organizacion_id' => $district['id'],
                'iglesia' => $district['iglesia'],
                'puntos_inscripcion' => $insPts,
                'puntos_subeventos' => round($subPts, 2),
                'puntos_total' => round($total, 2),
                'puntos_maximo' => $maxTotal > 0 ? round($maxTotal, 2) : null,
                'porcentaje' => $pct,
                'inscrito' => in_array($orgId, $inscritos, true),
            ];
        }

        $rows = $this->applySearch($rows, $q);
        $rows = $this->sortAndRank($rows, $sort);

        return [
            'evento' => [
                'id' => $root->id,
                'name' => $root->name,
                'estado' => $root->estado,
                'image_url' => $root->image_url,
            ],
            'alcance' => [
                'evento_id' => (int) $scope->id,
                'nombre' => $scope->name,
                'es_root' => $isRootScope,
                'puntaje_desde_hijos' => (bool) $scope->puntaje_desde_hijos,
                'puntaje_maximo' => $scope->puntaje_maximo !== null ? (float) $scope->puntaje_maximo : null,
            ],
            'subeventos' => $this->selectableScopes($root),
            'sort' => $sort,
            'totales' => [
                'clubes' => count($rows),
                'con_puntaje' => count(array_filter($rows, fn ($r) => (float) $r['puntos_total'] > 0)),
                'puntaje_maximo_alcance' => $isRootScope
                    ? round(
                        $maxSub + max(
                            (float) ($root->puntos_inscripcion_a_tiempo ?? 0),
                            (float) ($root->puntos_inscripcion_fuera_tiempo ?? 0),
                        ),
                        2,
                    )
                    : round($maxSub, 2),
            ],
            'standings' => $rows,
        ];
    }

    /**
     * Ranking jerárquico: árbol de subeventos + puntaje por nodo/club.
     *
     * @return array<string, mixed>
     */
    public function standingsTree(
        User $actor,
        Event $event,
        string $sort = 'puesto',
        ?string $q = null,
    ): array {
        $root = $this->participation->resolveRoot($event);

        if (! $actor->can('viewScores', $root)) {
            throw new AccessDeniedHttpException('No puedes ver los puntajes de este evento.');
        }

        $this->eagerLoadTree($root, 8);

        $sort = in_array($sort, ['puesto', 'nombre', 'distrito', 'puntaje'], true) ? $sort : 'puesto';
        $leafIds = $this->collectLeafScoreIds($root);
        $allNodeIds = $this->collectAllNodeIds($root);
        $maxSub = $this->leafMaxTotal($root, $leafIds);
        $insMax = max(
            (float) ($root->puntos_inscripcion_a_tiempo ?? 0),
            (float) ($root->puntos_inscripcion_fuera_tiempo ?? 0),
        );
        $maxTotal = $maxSub + $insMax;

        $inscritos = EventoInscripcion::query()
            ->where('evento_id', $root->id)
            ->where('tipo', 'club')
            ->whereNotNull('organizacion_id')
            ->pluck('organizacion_id')
            ->map(fn ($id) => (int) $id)
            ->unique()
            ->values()
            ->all();

        $orgIdsFromScores = [];
        if ($leafIds !== []) {
            $orgIdsFromScores = EventoCalificacion::query()
                ->whereIn('evento_id', $leafIds)
                ->whereNotNull('organizacion_id')
                ->whereNull('persona_id')
                ->pluck('organizacion_id')
                ->map(fn ($id) => (int) $id)
                ->unique()
                ->values()
                ->all();
        }

        $orgIds = array_values(array_unique(array_merge($inscritos, $orgIdsFromScores)));
        $tree = $this->buildTreeNode($root);

        if ($orgIds === []) {
            return [
                'evento' => [
                    'id' => $root->id,
                    'name' => $root->name,
                    'estado' => $root->estado,
                    'image_url' => $root->image_url,
                ],
                'tree' => $tree,
                'sort' => $sort,
                'totales' => [
                    'clubes' => 0,
                    'con_puntaje' => 0,
                    'puntaje_maximo_alcance' => round($maxTotal, 2),
                ],
                'standings' => [],
            ];
        }

        $orgs = Organizacion::query()
            ->whereIn('id', $orgIds)
            ->with(['padre.padre'])
            ->get(['id', 'nombre', 'organizacion_padre_id', 'tipo_organizacion_id'])
            ->keyBy('id');

        $clubs = Club::query()
            ->whereIn('organizacion_id', $orgIds)
            ->get(['id', 'organizacion_id', 'nombre', 'logo', 'distrito'])
            ->keyBy('organizacion_id');

        $matrix = $this->calificacionAggregator->averagedScoresMatrix($leafIds, $orgIds);
        $leavesByNode = $this->leafIdsByNode($root);

        $inscripcionByOrg = EventoCalificacion::query()
            ->where('evento_id', $root->id)
            ->whereIn('organizacion_id', $orgIds)
            ->whereNull('persona_id')
            ->whereNull('calificado_por')
            ->get()
            ->keyBy(fn (EventoCalificacion $c) => (int) $c->organizacion_id);

        $rows = [];
        foreach ($orgIds as $orgId) {
            $org = $orgs->get($orgId);
            if (! $org) {
                continue;
            }
            $club = $clubs->get($orgId);
            $district = $this->resolveDistrict($org, $club);
            $insPts = (float) ($inscripcionByOrg->get($orgId)?->puntaje_obtenido ?? 0);
            $leafMap = $matrix[$orgId] ?? [];

            $scores = [];
            foreach ($allNodeIds as $nodeId) {
                $nodeLeaves = $leavesByNode[$nodeId] ?? [];
                $sub = 0.0;
                foreach ($nodeLeaves as $leafId) {
                    $sub += (float) ($leafMap[$leafId] ?? 0);
                }
                if ($nodeId === (int) $root->id) {
                    $scores[(string) $nodeId] = round($insPts + $sub, 2);
                } else {
                    $scores[(string) $nodeId] = round($sub, 2);
                }
            }

            $total = (float) ($scores[(string) $root->id] ?? 0);
            $pct = $maxTotal > 0 ? (int) round(($total / $maxTotal) * 100) : null;

            $rows[] = [
                'organizacion_id' => $orgId,
                'club_id' => $club?->id,
                'nombre' => $club?->nombre ?: $org->nombre,
                'logo_url' => $club?->logo ?: null,
                'distrito' => $district['nombre'],
                'distrito_organizacion_id' => $district['id'],
                'iglesia' => $district['iglesia'],
                'puntos_inscripcion' => round($insPts, 2),
                'puntos_total' => round($total, 2),
                'puntos_maximo' => $maxTotal > 0 ? round($maxTotal, 2) : null,
                'porcentaje' => $pct,
                'inscrito' => in_array($orgId, $inscritos, true),
                'scores' => $scores,
            ];
        }

        $rows = $this->applySearch($rows, $q);
        $rows = $this->sortAndRank($rows, $sort);

        return [
            'evento' => [
                'id' => $root->id,
                'name' => $root->name,
                'estado' => $root->estado,
                'image_url' => $root->image_url,
            ],
            'tree' => $tree,
            'sort' => $sort,
            'totales' => [
                'clubes' => count($rows),
                'con_puntaje' => count(array_filter($rows, fn ($r) => (float) $r['puntos_total'] > 0)),
                'puntaje_maximo_alcance' => round($maxTotal, 2),
            ],
            'standings' => $rows,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function buildTreeNode(Event $node): array
    {
        $children = [];
        foreach ($node->hijos ?? [] as $hijo) {
            $children[] = $this->buildTreeNode($hijo);
        }

        $leafIds = $this->collectLeafScoreIds($node);
        $maxPts = $this->leafMaxTotal($node, $leafIds);
        if (! $node->evento_padre_id) {
            $maxPts += max(
                (float) ($node->puntos_inscripcion_a_tiempo ?? 0),
                (float) ($node->puntos_inscripcion_fuera_tiempo ?? 0),
            );
        }

        return [
            'id' => (int) $node->id,
            'name' => $node->name,
            'evento_padre_id' => $node->evento_padre_id !== null ? (int) $node->evento_padre_id : null,
            'es_root' => $node->evento_padre_id === null,
            'es_calificable' => (bool) $node->es_calificable,
            'puntaje_desde_hijos' => (bool) $node->puntaje_desde_hijos,
            'puntaje_maximo' => $node->puntaje_maximo !== null ? (float) $node->puntaje_maximo : null,
            'puntaje_maximo_rollup' => round($maxPts, 2),
            'has_children' => $children !== [],
            'children' => $children,
        ];
    }

    /**
     * @return list<int>
     */
    private function collectAllNodeIds(Event $root): array
    {
        $ids = [(int) $root->id];
        $walk = function (Event $n) use (&$walk, &$ids): void {
            foreach ($n->hijos ?? [] as $hijo) {
                $ids[] = (int) $hijo->id;
                $walk($hijo);
            }
        };
        $walk($root);

        return array_values(array_unique($ids));
    }

    /**
     * @return array<int, list<int>> nodeId => leafIds under that node
     */
    private function leafIdsByNode(Event $root): array
    {
        $map = [];
        $walk = function (Event $n) use (&$walk, &$map): void {
            $map[(int) $n->id] = $this->collectLeafScoreIds($n);
            foreach ($n->hijos ?? [] as $hijo) {
                $walk($hijo);
            }
        };
        $walk($root);

        return $map;
    }

    /**
     * @return array<string, mixed>
     */
    private function emptyPayload(Event $root, Event $scope, bool $isRootScope, string $sort, float $maxSub): array
    {
        return [
            'evento' => [
                'id' => $root->id,
                'name' => $root->name,
                'estado' => $root->estado,
                'image_url' => $root->image_url,
            ],
            'alcance' => [
                'evento_id' => (int) $scope->id,
                'nombre' => $scope->name,
                'es_root' => $isRootScope,
                'puntaje_desde_hijos' => (bool) $scope->puntaje_desde_hijos,
                'puntaje_maximo' => $scope->puntaje_maximo !== null ? (float) $scope->puntaje_maximo : null,
            ],
            'subeventos' => $this->selectableScopes($root),
            'sort' => $sort,
            'totales' => [
                'clubes' => 0,
                'con_puntaje' => 0,
                'puntaje_maximo_alcance' => round($maxSub, 2),
            ],
            'standings' => [],
        ];
    }

    /**
     * @return list<int>
     */
    private function collectLeafScoreIds(Event $node): array
    {
        if (! $node->puntaje_desde_hijos && $node->es_calificable && $node->evento_padre_id) {
            return [(int) $node->id];
        }

        if (! $node->puntaje_desde_hijos && $node->es_calificable && ! $node->evento_padre_id) {
            // Root calificable sin hijos: no entra como hoja de subeventos.
            $out = [];
        } else {
            $out = [];
        }

        $walk = function (Event $n) use (&$walk, &$out): void {
            if ($n->puntaje_desde_hijos) {
                foreach ($n->hijos ?? [] as $hijo) {
                    $walk($hijo);
                }

                return;
            }

            if ($n->es_calificable && $n->evento_padre_id) {
                $out[] = (int) $n->id;
            }

            foreach ($n->hijos ?? [] as $hijo) {
                $walk($hijo);
            }
        };

        foreach ($node->hijos ?? [] as $hijo) {
            $walk($hijo);
        }

        if ($out === [] && ! $node->puntaje_desde_hijos && $node->es_calificable && $node->evento_padre_id) {
            $out[] = (int) $node->id;
        }

        return array_values(array_unique($out));
    }

    /**
     * @param  list<int>  $leafIds
     */
    private function leafMaxTotal(Event $scope, array $leafIds): float
    {
        if ($leafIds === []) {
            return 0.0;
        }

        if (count($leafIds) === 1 && (int) $scope->id === $leafIds[0]) {
            return (float) ($scope->puntaje_maximo ?? 0);
        }

        $sum = 0.0;
        $walk = function (Event $n) use (&$walk, &$sum, $leafIds): void {
            if (in_array((int) $n->id, $leafIds, true)) {
                $sum += (float) ($n->puntaje_maximo ?? 0);
            }
            foreach ($n->hijos ?? [] as $hijo) {
                $walk($hijo);
            }
        };
        $walk($scope);

        return $sum;
    }

    /**
     * @param  list<int>  $leafIds
     * @param  list<int>  $orgIds
     * @return array<int, float>
     */
    private function scoresByOrg(array $leafIds, array $orgIds): array
    {
        if ($leafIds === [] || $orgIds === []) {
            return [];
        }

        $rows = EventoCalificacion::query()
            ->whereIn('evento_id', $leafIds)
            ->whereIn('organizacion_id', $orgIds)
            ->whereNull('persona_id')
            ->selectRaw('organizacion_id, SUM(puntaje_obtenido) as total')
            ->groupBy('organizacion_id')
            ->get();

        $out = [];
        foreach ($rows as $row) {
            $out[(int) $row->organizacion_id] = (float) $row->total;
        }

        return $out;
    }

    /**
     * @return array{id: int|null, nombre: string, iglesia: string|null}
     */
    private function resolveDistrict(Organizacion $org, ?Club $club): array
    {
        $current = $org;
        $iglesiaNombre = null;
        $distrito = null;
        $guard = 0;

        while ($current && $guard < 12) {
            $tipo = (int) ($current->tipo_organizacion_id ?? 0);
            if ($tipo === Organizacion::TIPO_DISTRITO) {
                $distrito = $current;
                break;
            }
            if ($tipo === Organizacion::TIPO_IGLESIA) {
                $iglesiaNombre = $current->nombre;
            }
            if (! $current->relationLoaded('padre')) {
                $current->loadMissing('padre');
            }
            $current = $current->padre;
            $guard++;
        }

        return [
            'id' => $distrito?->id,
            'nombre' => $distrito?->nombre ?: ($club?->distrito ?: '—'),
            'iglesia' => $iglesiaNombre,
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     * @return list<array<string, mixed>>
     */
    private function applySearch(array $rows, ?string $q): array
    {
        $term = trim((string) $q);
        if ($term === '') {
            return $rows;
        }
        $needle = mb_strtolower($term);

        return array_values(array_filter($rows, function (array $row) use ($needle) {
            $hay = mb_strtolower(
                ($row['nombre'] ?? '').' '.($row['distrito'] ?? '').' '.($row['iglesia'] ?? '')
            );

            return str_contains($hay, $needle);
        }));
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     * @return list<array<string, mixed>>
     */
    private function sortAndRank(array $rows, string $sort): array
    {
        usort($rows, function (array $a, array $b) use ($sort) {
            return match ($sort) {
                'nombre' => strcasecmp((string) $a['nombre'], (string) $b['nombre'])
                    ?: ((float) $b['puntos_total'] <=> (float) $a['puntos_total']),
                'distrito' => strcasecmp((string) $a['distrito'], (string) $b['distrito'])
                    ?: ((float) $b['puntos_total'] <=> (float) $a['puntos_total'])
                    ?: strcasecmp((string) $a['nombre'], (string) $b['nombre']),
                'puntaje', 'puesto' => ((float) $b['puntos_total'] <=> (float) $a['puntos_total'])
                    ?: strcasecmp((string) $a['nombre'], (string) $b['nombre']),
                default => ((float) $b['puntos_total'] <=> (float) $a['puntos_total']),
            };
        });

        $puesto = 0;
        $prevScore = null;
        $index = 0;
        foreach ($rows as &$row) {
            $index++;
            $score = (float) $row['puntos_total'];
            if ($prevScore === null || abs($score - $prevScore) > 0.001) {
                $puesto = $index;
                $prevScore = $score;
            }
            $row['puesto'] = $puesto;
        }
        unset($row);

        return $rows;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function selectableScopes(Event $root): array
    {
        $out = [[
            'id' => (int) $root->id,
            'name' => $root->name,
            'label' => $root->name.' (total)',
            'es_root' => true,
            'evento_padre_id' => null,
            'puntaje_maximo' => $root->puntaje_maximo !== null ? (float) $root->puntaje_maximo : null,
            'puntaje_desde_hijos' => (bool) $root->puntaje_desde_hijos,
        ]];

        $walk = function (Event $node, string $path) use (&$walk, &$out): void {
            foreach ($node->hijos ?? [] as $hijo) {
                $label = $path === '' ? $hijo->name : $path.' › '.$hijo->name;
                $selectable = $hijo->es_calificable || $hijo->puntaje_desde_hijos || ($hijo->hijos?->isNotEmpty() ?? false);
                if ($selectable) {
                    $out[] = [
                        'id' => (int) $hijo->id,
                        'name' => $hijo->name,
                        'label' => $label,
                        'es_root' => false,
                        'evento_padre_id' => (int) $hijo->evento_padre_id,
                        'puntaje_maximo' => $hijo->puntaje_maximo !== null ? (float) $hijo->puntaje_maximo : null,
                        'puntaje_desde_hijos' => (bool) $hijo->puntaje_desde_hijos,
                    ];
                }
                $walk($hijo, $label);
            }
        };
        $walk($root, '');

        return $out;
    }

    private function findInTree(Event $root, int $id): ?Event
    {
        if ((int) $root->id === $id) {
            return $root;
        }
        foreach ($root->hijos ?? [] as $hijo) {
            $found = $this->findInTree($hijo, $id);
            if ($found) {
                return $found;
            }
        }

        return null;
    }

    private function eagerLoadTree(Event $event, int $depth): void
    {
        if ($depth <= 0) {
            return;
        }

        $event->loadMissing([
            'hijos' => fn ($q) => $q->orderBy('orden')->orderBy('id'),
        ]);

        foreach ($event->hijos as $hijo) {
            $this->eagerLoadTree($hijo, $depth - 1);
        }
    }
}
