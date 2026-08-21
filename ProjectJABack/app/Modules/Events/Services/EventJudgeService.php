<?php

namespace App\Modules\Events\Services;

use App\Models\User;
use App\Modules\Clubs\Models\Club;
use App\Modules\Events\Models\Event;
use App\Modules\Events\Models\EventoActividadParticipante;
use App\Modules\Events\Models\EventoCalificacion;
use App\Modules\Events\Models\EventoCalificacionDetalle;
use App\Modules\Events\Models\EventoCalificacionObsDirector;
use App\Modules\Events\Models\EventoEvidencia;
use App\Modules\Events\Models\EventoInscripcion;
use App\Modules\Organizations\Models\Organizacion;
use App\Modules\Organizations\Services\OrganizationAccessService;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

/**
 * Panel de calificación del juez.
 */
final class EventJudgeService
{
    public function __construct(
        private readonly OrganizationAccessService $orgAccess,
        private readonly EventParticipationService $participation,
    ) {}

    public function assertCanEvaluate(User $actor, Event $event): Event
    {
        $root = $this->participation->resolveRoot($event);

        if (! $actor->can('evaluate', $root)) {
            throw new AccessDeniedHttpException('No puedes evaluar este evento.');
        }

        return $root;
    }

    public function ensureTreeLoaded(Event $event, int $depth = 8): void
    {
        $this->eagerLoadTree($event, $depth);
    }

    /**
     * @return array{open: bool, assigned_ids: array<int, true>, visible_ids: array<int, true>}
     */
    public function scopeForActor(Event $root, User $actor): array
    {
        return $this->resolveJudgeScope($root, $actor);
    }

    /**
     * @param  list<int>  $eventIds
     * @return array<string, array<string, int>>
     */
    public function pendientesMapPublic(array $eventIds, User $actor): array
    {
        return $this->buildPendientesMap($eventIds, $actor);
    }

    /**
     * @param  list<int>  $eventIds
     * @return array<string, array<string, float>>
     */
    public function evaluadosMapPublic(array $eventIds, User $actor): array
    {
        return $this->buildEvaluadosMap($eventIds, $actor);
    }

    /**
     * @return array<string, mixed>
     */
    public function board(User $actor, Event $event, ?int $subeventoId = null, ?int $actividadId = null): array
    {
        $root = $this->assertCanEvaluate($actor, $event);
        $this->eagerLoadTree($root, 8);

        $scope = $this->resolveJudgeScope($root, $actor);

        $subeventos = $this->collectSelectables($root, $scope);
        $selectedMeta = null;

        if ($subeventoId) {
            $selectedMeta = collect($subeventos)->firstWhere('id', $subeventoId);
            if (! $selectedMeta) {
                throw ValidationException::withMessages([
                    'subevento_id' => ['El subevento no pertenece a este evento o no es evaluable.'],
                ]);
            }
        } elseif ($subeventos !== []) {
            // Preferir un nodo asignado/calificable; si no, el primero visible.
            $selectedMeta = collect($subeventos)->first(
                fn (array $s) => ! empty($s['puede_calificar'])
            ) ?? $subeventos[0];
            $subeventoId = (int) $selectedMeta['id'];
        }

        $clubes = [];
        $progreso = ['evaluados' => 0, 'pendientes' => 0, 'total' => 0, 'pct' => 0];
        $selected = null;
        $actividad = null;

        if ($selectedMeta && $subeventoId) {
            $branch = $this->findInTree($root, $subeventoId);
            if (! $branch || ! $this->isVisibleInScope((int) $branch->id, $scope)) {
                throw ValidationException::withMessages([
                    'subevento_id' => ['Subevento no encontrado en el árbol del evento.'],
                ]);
            }

            $hijosCalificables = $this->calificableDescendants($branch, $scope);
            $target = $branch;

            if ($actividadId) {
                if (! $this->isVisibleInScope($actividadId, $scope)) {
                    throw ValidationException::withMessages([
                        'actividad_id' => ['La actividad no está en tu alcance de juez.'],
                    ]);
                }
                $candidate = collect($hijosCalificables)->firstWhere('id', $actividadId);
                if (! $candidate && (int) $branch->id !== $actividadId) {
                    throw ValidationException::withMessages([
                        'actividad_id' => ['La actividad no pertenece al subevento seleccionado.'],
                    ]);
                }
                $target = $this->findInTree($root, $actividadId) ?? $branch;
            }

            $target->load([
                'criterios' => fn ($q) => $q->orderByPivot('orden'),
                'tipoEvento:id,nombre,slug,color,icono',
                'categoriaSubevento:id,nombre,slug,color,icono,maneja_puntos,maneja_fecha_inicio,maneja_fecha_fin',
                'jueces:id,name,email',
                'supervisores:id,name,email',
            ]);
            $branch->loadMissing(['hijos']);

            $selected = $this->mapSubevento($branch, $hijosCalificables, $scope, $root);
            $actividad = $this->mapSubevento($target, [], $scope, $root);
            $clubes = $this->clubsForScope($branch, $target, $actor);

            $evaluados = count(array_filter($clubes, fn ($c) => $c['estado'] === 'evaluado'));
            $total = count($clubes);
            $progreso = [
                'evaluados' => $evaluados,
                'pendientes' => max(0, $total - $evaluados),
                'total' => $total,
                'pct' => $total ? (int) round(($evaluados / $total) * 100) : 0,
            ];
        }

        $scopedIds = $scope['open']
            ? $this->collectSubtreeIds($root)
            : array_values(array_map('intval', array_keys($scope['assigned_ids'])));

        $allowedOrgLookup = $this->judgeAllowedOrgLookup($actor);

        $pendientes = $this->filterOrgKeyedMap($this->buildPendientesMap($scopedIds, $actor), $allowedOrgLookup);
        $evaluados = $this->filterOrgKeyedMap($this->buildEvaluadosMap($scopedIds, $actor), $allowedOrgLookup);
        $evidencias = $this->filterOrgKeyedMap($this->buildEvidenciasMap($scopedIds), $allowedOrgLookup);
        $clubesResumen = $this->buildClubesResumen($pendientes, $evaluados, $evidencias, $allowedOrgLookup);

        // Progreso global del juez: clubes con al menos un evento pendiente vs total.
        if ($clubesResumen !== []) {
            $conPendientes = count(array_filter(
                $clubesResumen,
                fn (array $c) => (int) ($c['eventos_pendientes'] ?? 0) > 0
            ));
            $totalClubes = count($clubesResumen);
            $progreso = [
                'evaluados' => max(0, $totalClubes - $conPendientes),
                'pendientes' => $conPendientes,
                'total' => $totalClubes,
                'pct' => $totalClubes ? (int) round((($totalClubes - $conPendientes) / $totalClubes) * 100) : 0,
            ];
        }

        return [
            'evento' => $this->eventHeader($root),
            'subeventos' => $subeventos,
            'arbol' => $this->buildJudgeTree($root, $scope),
            'subevento' => $selected,
            'actividad' => $actividad,
            'clubes' => $clubes,
            'clubes_resumen' => $clubesResumen,
            'progreso' => $progreso,
            // organizacion_id => [ evento_id => evidencias pendientes de calificar ]
            'pendientes' => $pendientes,
            // organizacion_id => [ evento_id => puntaje_obtenido ]
            'evaluados' => $evaluados,
            // organizacion_id => [ evento_id => total evidencias cargadas ]
            'evidencias' => $evidencias,
        ];
    }

    /**
     * Listado "Mis evaluaciones": clubes inscritos en alcance del juez + detalle.
     *
     * @return array<string, mixed>
     */
    public function evaluaciones(
        User $actor,
        Event $event,
        ?string $q = null,
        ?string $estado = null,
        ?string $distrito = null,
        ?int $subeventoId = null,
        ?int $organizacionId = null,
    ): array {
        $root = $this->assertCanEvaluate($actor, $event);
        $this->eagerLoadTree($root, 8);

        $scope = $this->resolveJudgeScope($root, $actor);
        $allowedOrgLookup = $this->judgeAllowedOrgLookup($actor);
        $scoreableIds = $this->collectScoreableIds($root, $scope);

        if ($subeventoId) {
            if (! in_array($subeventoId, $scoreableIds, true) && ! $this->isVisibleInScope($subeventoId, $scope)) {
                throw ValidationException::withMessages([
                    'subevento_id' => ['El subevento no está en tu alcance de juez.'],
                ]);
            }
            $branch = $this->findInTree($root, $subeventoId);
            if (! $branch) {
                throw ValidationException::withMessages([
                    'subevento_id' => ['Subevento no encontrado.'],
                ]);
            }
            $scoreableIds = array_values(array_intersect(
                $scoreableIds,
                $this->collectScoreableIds($branch, $scope) ?: [(int) $branch->id],
            ));
            if ($this->canScoreInScope($branch, $scope)) {
                $scoreableIds[] = (int) $branch->id;
                $scoreableIds = array_values(array_unique($scoreableIds));
            }
        }

        $inscritos = EventoInscripcion::query()
            ->where('evento_id', $root->id)
            ->where('tipo', 'club')
            ->whereNotNull('organizacion_id')
            ->pluck('organizacion_id')
            ->map(fn ($id) => (int) $id)
            ->unique()
            ->filter(fn (int $id) => $this->orgIdAllowed($id, $allowedOrgLookup))
            ->values()
            ->all();

        $pendientes = $this->filterOrgKeyedMap($this->buildPendientesMap($scoreableIds, $actor), $allowedOrgLookup);
        $evaluados = $this->filterOrgKeyedMap($this->buildEvaluadosMap($scoreableIds, $actor), $allowedOrgLookup);
        $evidencias = $this->filterOrgKeyedMap($this->buildEvidenciasMap($scoreableIds), $allowedOrgLookup);

        // Incluir inscritos aunque no tengan evidencia aún.
        $orgIds = $inscritos;
        foreach (array_keys($evaluados) as $orgKey) {
            $orgIds[] = (int) $orgKey;
        }
        foreach (array_keys($evidencias) as $orgKey) {
            $orgIds[] = (int) $orgKey;
        }
        foreach (array_keys($pendientes) as $orgKey) {
            $orgIds[] = (int) $orgKey;
        }
        $orgIds = array_values(array_unique(array_filter(
            $orgIds,
            fn (int $id) => $this->orgIdAllowed($id, $allowedOrgLookup)
        )));

        $eventMeta = $scoreableIds === []
            ? collect()
            : Event::query()
                ->whereIn('id', $scoreableIds)
                ->get(['id', 'name', 'puntaje_maximo', 'image_url', 'requiere_evidencia'])
                ->keyBy('id');

        $subeventosFiltro = $eventMeta
            ->map(fn (Event $ev) => [
                'id' => (int) $ev->id,
                'name' => $ev->name,
            ])
            ->values()
            ->all();

        if ($orgIds === []) {
            return [
                'evento' => $this->eventHeader($root),
                'filtros' => [
                    'distritos' => [],
                    'subeventos' => $subeventosFiltro,
                ],
                'totales' => [
                    'evaluados' => 0,
                    'completos' => 0,
                    'pendientes' => 0,
                    'sin_evidencia' => 0,
                    'promedio_pct' => null,
                    'total' => 0,
                ],
                'clubes' => [],
                'detalle' => null,
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

        $latestByOrg = EventoCalificacion::query()
            ->whereIn('evento_id', $scoreableIds === [] ? [-1] : $scoreableIds)
            ->whereIn('organizacion_id', $orgIds)
            ->whereNull('persona_id')
            ->where('calificado_por', $actor->id)
            ->orderByDesc('updated_at')
            ->get(['organizacion_id', 'evento_id', 'updated_at', 'puntaje_obtenido', 'observaciones']);

        $latestMap = [];
        foreach ($latestByOrg as $row) {
            $oid = (int) $row->organizacion_id;
            if (! isset($latestMap[$oid])) {
                $latestMap[$oid] = $row;
            }
        }

        $rows = [];
        $distritosSet = [];
        $sumPct = 0.0;
        $pctCount = 0;

        foreach ($orgIds as $orgId) {
            $org = $orgs->get($orgId);
            if (! $org) {
                continue;
            }
            $club = $clubs->get($orgId);
            $district = $this->resolveDistrict($org, $club);
            $distritoNombre = $district['nombre'];
            if ($distritoNombre !== '' && $distritoNombre !== '—') {
                $distritosSet[$distritoNombre] = true;
            }

            $pendingByEvent = $pendientes[(string) $orgId] ?? [];
            $scoredByEvent = $evaluados[(string) $orgId] ?? [];
            $evidenceByEvent = $evidencias[(string) $orgId] ?? [];

            $eventosPendientes = count($pendingByEvent);
            $eventosEvaluados = count($scoredByEvent);
            $evidenciasCount = (int) array_sum($evidenceByEvent);

            if ($eventosPendientes > 0) {
                $estadoClub = 'pendiente';
            } elseif ($eventosEvaluados > 0) {
                $estadoClub = 'completado';
            } else {
                $estadoClub = 'sin_evidencia';
            }

            $puntosOtorgados = round((float) array_sum($scoredByEvent), 2);
            $puntosMax = 0.0;
            foreach (array_keys($scoredByEvent) as $eid) {
                $meta = $eventMeta->get((int) $eid);
                $puntosMax += (float) ($meta?->puntaje_maximo ?? 0);
            }
            $pct = $puntosMax > 0 ? (int) round(($puntosOtorgados / $puntosMax) * 100) : null;
            if ($pct !== null) {
                $sumPct += $pct;
                $pctCount++;
            }

            $latest = $latestMap[$orgId] ?? null;
            $subeventoEvaluado = null;
            if ($latest) {
                $meta = $eventMeta->get((int) $latest->evento_id);
                $subeventoEvaluado = $meta?->name;
            } elseif ($pendingByEvent !== []) {
                $firstPending = (int) array_key_first($pendingByEvent);
                $subeventoEvaluado = $eventMeta->get($firstPending)?->name;
            }

            $rows[] = [
                'organizacion_id' => $orgId,
                'club_id' => $club?->id,
                'nombre' => $club?->nombre ?: $org->nombre,
                'logo_url' => $club?->logo ?: null,
                'distrito' => $distritoNombre,
                'iglesia' => $district['iglesia'],
                'estado' => $estadoClub,
                'eventos_pendientes' => $eventosPendientes,
                'eventos_evaluados' => $eventosEvaluados,
                'evidencias_count' => $evidenciasCount,
                'puntaje_otorgado' => $puntosOtorgados,
                'puntaje_maximo' => $puntosMax > 0 ? round($puntosMax, 2) : null,
                'porcentaje' => $pct,
                'subevento_evaluado' => $subeventoEvaluado,
                'updated_at' => $latest?->updated_at?->toIso8601String(),
                'inscrito' => in_array($orgId, $inscritos, true),
            ];
        }

        $estado = $estado && in_array($estado, ['completado', 'pendiente', 'sin_evidencia'], true)
            ? $estado
            : null;
        $term = trim((string) $q);
        $distritoFilter = trim((string) $distrito);

        $rows = array_values(array_filter($rows, function (array $row) use ($estado, $term, $distritoFilter) {
            if ($estado && $row['estado'] !== $estado) {
                return false;
            }
            if ($distritoFilter !== '' && strcasecmp((string) $row['distrito'], $distritoFilter) !== 0) {
                return false;
            }
            if ($term !== '') {
                $hay = mb_strtolower(
                    ($row['nombre'] ?? '').' '.($row['distrito'] ?? '').' '.($row['iglesia'] ?? '')
                );
                if (! str_contains($hay, mb_strtolower($term))) {
                    return false;
                }
            }

            return true;
        }));

        usort($rows, function (array $a, array $b) {
            $order = ['pendiente' => 0, 'sin_evidencia' => 1, 'completado' => 2];
            $oa = $order[$a['estado']] ?? 9;
            $ob = $order[$b['estado']] ?? 9;
            if ($oa !== $ob) {
                return $oa <=> $ob;
            }

            return strcasecmp((string) $a['nombre'], (string) $b['nombre']);
        });

        $completos = count(array_filter($rows, fn ($r) => $r['estado'] === 'completado'));
        $pendientesCount = count(array_filter($rows, fn ($r) => $r['estado'] === 'pendiente'));
        $sinEvidencia = count(array_filter($rows, fn ($r) => $r['estado'] === 'sin_evidencia'));

        $detalle = null;
        if ($organizacionId) {
            if (! $this->orgIdAllowed($organizacionId, $allowedOrgLookup)) {
                throw new AccessDeniedHttpException('No puedes ver ese club.');
            }
            $detalle = $this->buildEvaluacionDetalle(
                $actor,
                $root,
                $organizacionId,
                $scoreableIds,
                $orgs->get($organizacionId),
                $clubs->get($organizacionId),
                $eventMeta,
                $evaluados[(string) $organizacionId] ?? [],
                $evidencias[(string) $organizacionId] ?? [],
                $pendientes[(string) $organizacionId] ?? [],
            );
        }

        return [
            'evento' => $this->eventHeader($root),
            'filtros' => [
                'distritos' => array_values(array_keys($distritosSet)),
                'subeventos' => $subeventosFiltro,
            ],
            'totales' => [
                'evaluados' => count($rows),
                'completos' => $completos,
                'pendientes' => $pendientesCount,
                'sin_evidencia' => $sinEvidencia,
                'promedio_pct' => $pctCount > 0 ? (int) round($sumPct / $pctCount) : null,
                'total' => count($rows),
            ],
            'clubes' => $rows,
            'detalle' => $detalle,
        ];
    }

    /**
     * @param  list<int>  $scoreableIds
     * @param  Collection<int, Event>  $eventMeta
     * @param  array<string, float>  $scoredByEvent
     * @param  array<string, int>  $evidenceByEvent
     * @param  array<string, int>  $pendingByEvent
     * @return array<string, mixed>
     */
    private function buildEvaluacionDetalle(
        User $actor,
        Event $root,
        int $organizacionId,
        array $scoreableIds,
        ?Organizacion $org,
        ?Club $club,
        $eventMeta,
        array $scoredByEvent,
        array $evidenceByEvent,
        array $pendingByEvent,
    ): array {
        if (! $org) {
            throw ValidationException::withMessages([
                'organizacion_id' => ['Organización no encontrada.'],
            ]);
        }

        $district = $this->resolveDistrict($org, $club);

        $cals = EventoCalificacion::query()
            ->whereIn('evento_id', $scoreableIds === [] ? [-1] : $scoreableIds)
            ->where('organizacion_id', $organizacionId)
            ->whereNull('persona_id')
            ->where('calificado_por', $actor->id)
            ->with('detalles')
            ->get()
            ->keyBy(fn (EventoCalificacion $c) => (int) $c->evento_id);

        $evidencias = EventoEvidencia::query()
            ->whereIn('evento_id', $scoreableIds === [] ? [-1] : $scoreableIds)
            ->where('organizacion_id', $organizacionId)
            ->with('file:id,name,path,mime_type,size')
            ->orderByDesc('id')
            ->get();

        $obsDirector = EventoCalificacionObsDirector::query()
            ->whereIn('evento_id', $scoreableIds === [] ? [-1] : $scoreableIds)
            ->where('organizacion_id', $organizacionId)
            ->get()
            ->keyBy(fn (EventoCalificacionObsDirector $o) => (int) $o->evento_id);

        $desglose = [];
        $allEventIds = array_values(array_unique(array_merge(
            array_map('intval', array_keys($scoredByEvent)),
            array_map('intval', array_keys($evidenceByEvent)),
            array_map('intval', array_keys($pendingByEvent)),
        )));
        sort($allEventIds);

        foreach ($allEventIds as $eventoId) {
            $meta = $eventMeta->get($eventoId);
            if (! $meta) {
                $meta = $this->findInTree($root, $eventoId);
            }
            if (! $meta) {
                continue;
            }
            $name = $meta->name;
            $max = $meta->puntaje_maximo !== null ? (float) $meta->puntaje_maximo : null;
            $imageUrl = $meta->image_url ?? null;
            $cal = $cals->get($eventoId);
            $score = $cal ? (float) $cal->puntaje_obtenido : null;
            $pct = $max && $score !== null ? (int) round(($score / $max) * 100) : null;
            $evItems = $evidencias->where('evento_id', $eventoId)->values();
            $estadoItem = $cal
                ? 'completado'
                : ($evItems->isNotEmpty() ? 'pendiente' : 'sin_evidencia');

            $desglose[] = [
                'evento_id' => $eventoId,
                'name' => $name,
                'image_url' => $imageUrl,
                'puntaje_obtenido' => $score,
                'puntaje_maximo' => $max,
                'porcentaje' => $pct,
                'estado' => $estadoItem,
                'observaciones' => $cal?->observaciones,
                'observaciones_director' => $obsDirector->get($eventoId)?->observaciones,
                'updated_at' => $cal?->updated_at?->toIso8601String(),
                'evidencias' => $evItems
                    ->map(fn (EventoEvidencia $e) => $this->participation->evidenciaPayload($e))
                    ->values()
                    ->all(),
                'calificacion' => $cal ? $this->calificacionPayload($cal) : null,
            ];
        }

        usort($desglose, fn ($a, $b) => strcasecmp((string) $a['name'], (string) $b['name']));

        $puntosOtorgados = round((float) array_sum($scoredByEvent), 2);
        $puntosMax = 0.0;
        foreach (array_keys($scoredByEvent) as $eid) {
            $m = $eventMeta->get((int) $eid);
            $puntosMax += (float) ($m?->puntaje_maximo ?? 0);
        }

        return [
            'organizacion_id' => $organizacionId,
            'nombre' => $club?->nombre ?: $org->nombre,
            'logo_url' => $club?->logo ?: null,
            'distrito' => $district['nombre'],
            'iglesia' => $district['iglesia'],
            'puntaje_otorgado' => $puntosOtorgados,
            'puntaje_maximo' => $puntosMax > 0 ? round($puntosMax, 2) : null,
            'porcentaje' => $puntosMax > 0 ? (int) round(($puntosOtorgados / $puntosMax) * 100) : null,
            'evaluado_por' => $actor->name,
            'desglose' => $desglose,
        ];
    }

    /**
     * @param  array{open: bool, assigned_ids: array<int, true>, visible_ids: array<int, true>}  $scope
     * @return list<int>
     */
    private function collectScoreableIds(Event $node, array $scope): array
    {
        $ids = [];
        $walk = function (Event $n) use (&$walk, &$ids, $scope): void {
            if ($this->canScoreInScope($n, $scope)) {
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
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public function saveScore(User $actor, Event $subevento, array $data): array
    {
        $root = $this->assertCanEvaluate($actor, $subevento);

        if ((int) $this->participation->resolveRoot($subevento)->id !== (int) $root->id) {
            throw ValidationException::withMessages([
                'evento' => ['El subevento no pertenece al evento evaluado.'],
            ]);
        }

        if (! $subevento->es_calificable) {
            throw ValidationException::withMessages([
                'evento' => ['Este subevento no es calificable. Califica una actividad hija.'],
            ]);
        }

        if ($subevento->puntaje_desde_hijos) {
            throw ValidationException::withMessages([
                'evento' => ['Este evento toma el puntaje de sus hijos. Califica las actividades hijas.'],
            ]);
        }

        $this->assertCanScoreActivity($actor, $root, $subevento);

        $orgId = (int) ($data['organizacion_id'] ?? 0);
        if ($orgId <= 0) {
            throw ValidationException::withMessages([
                'organizacion_id' => ['La organización es obligatoria.'],
            ]);
        }

        if (! $this->orgAccess->canAccessOrganization($actor, $orgId)) {
            throw new AccessDeniedHttpException(
                'Ese club está fuera del alcance de tu organización.'
            );
        }

        Organizacion::query()->findOrFail($orgId);

        $puestoEntrega = isset($data['puesto_entrega']) ? trim((string) $data['puesto_entrega']) : null;
        $puestoEntrega = $puestoEntrega !== '' ? $puestoEntrega : null;
        $tiempoEntrega = $this->normalizeTiempoEntrega($data['tiempo_entrega'] ?? null);
        $resultadoObtenido = isset($data['resultado_obtenido']) ? (int) $data['resultado_obtenido'] : null;
        $resultadoEsperado = $subevento->resultado_esperado !== null ? (int) $subevento->resultado_esperado : null;

        if ($subevento->requiere_puesto_entrega && $puestoEntrega === null) {
            throw ValidationException::withMessages([
                'puesto_entrega' => ['Indica el puesto de entrega.'],
            ]);
        }
        if ($subevento->requiere_tiempo_entrega && $tiempoEntrega === null) {
            throw ValidationException::withMessages([
                'tiempo_entrega' => ['Indica el tiempo de entrega.'],
            ]);
        }
        if ($resultadoEsperado !== null) {
            if ($resultadoObtenido === null) {
                throw ValidationException::withMessages([
                    'resultado_obtenido' => ['Indica cuántos resultados están bien.'],
                ]);
            }
            if ($resultadoObtenido < 0 || $resultadoObtenido > $resultadoEsperado) {
                throw ValidationException::withMessages([
                    'resultado_obtenido' => ["El resultado debe estar entre 0 y {$resultadoEsperado}."],
                ]);
            }
        } else {
            $resultadoObtenido = null;
        }

        $subevento->load(['criterios' => fn ($q) => $q->orderByPivot('orden')]);
        $criterios = $subevento->criterios;
        $max = $subevento->puntaje_maximo !== null ? (float) $subevento->puntaje_maximo : null;
        $observaciones = isset($data['observaciones'])
            ? (trim((string) $data['observaciones']) ?: null)
            : null;

        $detallesInput = is_array($data['criterios'] ?? null) ? $data['criterios'] : [];
        $total = 0.0;
        $detallesNorm = [];

        if ($subevento->puntaje_por_participar) {
            if ($max === null) {
                throw ValidationException::withMessages([
                    'puntaje_obtenido' => ['Esta actividad no tiene puntaje máximo configurado.'],
                ]);
            }
            $total = $max;
        } elseif ($criterios->isNotEmpty()) {
            $byId = $criterios->keyBy('id');
            $seen = [];

            foreach ($detallesInput as $row) {
                $criterioId = (int) ($row['criterio_evaluacion_id'] ?? 0);
                $puntos = round((float) ($row['puntos'] ?? 0), 2);
                $criterio = $byId->get($criterioId);

                if (! $criterio) {
                    throw ValidationException::withMessages([
                        'criterios' => ["Criterio {$criterioId} no asignado a este subevento."],
                    ]);
                }

                $tope = (float) $criterio->pivot->puntos;
                if ($puntos < 0 || $puntos > $tope + 0.001) {
                    throw ValidationException::withMessages([
                        'criterios' => ["El puntaje de \"{$criterio->nombre}\" no puede superar {$tope}."],
                    ]);
                }

                $seen[$criterioId] = true;
                $detallesNorm[] = [
                    'criterio_evaluacion_id' => $criterioId,
                    'puntos' => $puntos,
                ];
                $total += $puntos;
            }

            foreach ($criterios as $criterio) {
                if (! isset($seen[$criterio->id])) {
                    $detallesNorm[] = [
                        'criterio_evaluacion_id' => (int) $criterio->id,
                        'puntos' => 0.0,
                    ];
                }
            }
        } elseif ($resultadoEsperado !== null && $resultadoObtenido !== null && $max !== null) {
            $total = round(($resultadoObtenido / $resultadoEsperado) * $max, 2);
        } else {
            $total = round((float) ($data['puntaje_obtenido'] ?? 0), 2);
            if ($total < 0) {
                throw ValidationException::withMessages([
                    'puntaje_obtenido' => ['El puntaje no puede ser negativo.'],
                ]);
            }
        }

        $total = round($total, 2);
        if ($max !== null && $total > $max + 0.001) {
            throw ValidationException::withMessages([
                'puntaje_obtenido' => ["El puntaje total no puede superar {$max}."],
            ]);
        }

        $calificacion = DB::transaction(function () use (
            $actor,
            $subevento,
            $orgId,
            $total,
            $observaciones,
            $detallesNorm,
            $puestoEntrega,
            $tiempoEntrega,
            $resultadoObtenido,
        ) {
            $calificacion = EventoCalificacion::query()->updateOrCreate(
                [
                    'evento_id' => $subevento->id,
                    'organizacion_id' => $orgId,
                    'persona_id' => null,
                    'calificado_por' => $actor->id,
                ],
                [
                    'puntaje_obtenido' => $total,
                    'observaciones' => $observaciones,
                    'puesto_entrega' => $puestoEntrega,
                    'tiempo_entrega' => $tiempoEntrega,
                    'resultado_obtenido' => $resultadoObtenido,
                ],
            );

            EventoCalificacionDetalle::query()
                ->where('calificacion_id', $calificacion->id)
                ->delete();

            foreach ($detallesNorm as $row) {
                EventoCalificacionDetalle::query()->create([
                    'calificacion_id' => $calificacion->id,
                    'criterio_evaluacion_id' => $row['criterio_evaluacion_id'],
                    'puntos' => $row['puntos'],
                ]);
            }

            return $calificacion->load('detalles');
        });

        return $this->calificacionPayload($calificacion);
    }

    /**
     * Alcance del juez: nodos asignados + ancestros (contexto, sin calificar).
     *
     * @return array{
     *   open: bool,
     *   assigned_ids: array<int, true>,
     *   visible_ids: array<int, true>
     * }
     */
    private function resolveJudgeScope(Event $root, User $actor): array
    {
        if ($this->orgAccess->bypassesOrganizationScope($actor)) {
            return [
                'open' => true,
                'assigned_ids' => [],
                'visible_ids' => [],
            ];
        }

        $assigned = [];
        $hasAnyJuez = false;
        $walk = function (Event $node, $inheritedJueces) use (&$walk, &$assigned, &$hasAnyJuez, $actor): void {
            $own = $node->ownJueces();
            if ($own->isNotEmpty()) {
                $hasAnyJuez = true;
            }

            [$effective] = $node->resolveEffectiveJueces($inheritedJueces);

            if ($effective->contains(fn ($u) => (int) $u->id === (int) $actor->id)) {
                $assigned[(int) $node->id] = true;
            }

            $passDown = $own->isNotEmpty() ? $own : $effective;
            foreach ($node->hijos ?? [] as $hijo) {
                $walk($hijo, $passDown);
            }
        };
        $walk($root, null);

        if (! $hasAnyJuez) {
            // Sin jueces en el árbol: modo abierto (compatibilidad).
            return [
                'open' => true,
                'assigned_ids' => [],
                'visible_ids' => [],
            ];
        }

        $visible = $assigned;
        $addAncestors = function (Event $node) use (&$addAncestors, &$visible, $root): void {
            $parentId = $node->evento_padre_id ? (int) $node->evento_padre_id : null;
            if (! $parentId || (int) $root->id === $parentId) {
                // El root del camporee no se lista como subevento; no hace falta marcarlo.
                return;
            }
            $visible[$parentId] = true;
            $parent = $this->findInTree($root, $parentId);
            if ($parent) {
                $addAncestors($parent);
            }
        };

        foreach (array_keys($assigned) as $id) {
            $node = $this->findInTree($root, (int) $id);
            if ($node) {
                $addAncestors($node);
            }
        }

        return [
            'open' => false,
            'assigned_ids' => $assigned,
            'visible_ids' => $visible,
        ];
    }

    /**
     * @param  array{open: bool, assigned_ids: array<int, true>, visible_ids: array<int, true>}  $scope
     */
    private function isVisibleInScope(int $eventoId, array $scope): bool
    {
        if ($scope['open']) {
            return true;
        }

        return isset($scope['visible_ids'][$eventoId]);
    }

    /**
     * @param  array{open: bool, assigned_ids: array<int, true>, visible_ids: array<int, true>}  $scope
     */
    private function canScoreInScope(Event $node, array $scope): bool
    {
        if (! $node->es_calificable || $node->puntaje_desde_hijos) {
            return false;
        }
        if ($scope['open']) {
            return true;
        }

        return isset($scope['assigned_ids'][(int) $node->id]);
    }

    private function assertCanScoreActivity(User $actor, Event $root, Event $subevento): void
    {
        if ($this->orgAccess->bypassesOrganizationScope($actor)) {
            return;
        }

        $this->eagerLoadTree($root, 8);
        $scope = $this->resolveJudgeScope($root, $actor);

        if ($scope['open']) {
            return;
        }

        if (! isset($scope['assigned_ids'][(int) $subevento->id])) {
            throw new AccessDeniedHttpException(
                'Solo puedes calificar los subeventos a los que estás asignado como juez.'
            );
        }
    }

    /**
     * Nodos seleccionables: calificables o padres con hijos calificables (en alcance).
     *
     * @param  array{open: bool, assigned_ids: array<int, true>, visible_ids: array<int, true>}  $scope
     * @return list<array<string, mixed>>
     */
    private function collectSelectables(Event $root, array $scope): array
    {
        $out = [];
        $walk = function (Event $node, int $depth, string $path) use (&$walk, &$out, $scope): void {
            if ($node->evento_padre_id && $this->isVisibleInScope((int) $node->id, $scope)) {
                $hijos = $this->calificableDescendants($node, $scope);
                $puedeCalificar = $this->canScoreInScope($node, $scope);
                $include = $scope['open']
                    ? ($puedeCalificar || $hijos !== [])
                    : true;

                if ($include) {
                    $actividadIds = [];
                    if ($puedeCalificar) {
                        $actividadIds[] = (int) $node->id;
                    }
                    foreach ($hijos as $hijo) {
                        $actividadIds[] = (int) $hijo['id'];
                    }
                    $labelPath = $path === '' ? $node->name : $path.' › '.$node->name;
                    $out[] = [
                        'id' => $node->id,
                        'name' => $node->name,
                        'label' => $labelPath,
                        'depth' => $depth,
                        'puntaje_maximo' => $node->puntaje_maximo !== null ? (float) $node->puntaje_maximo : null,
                        'requiere_evidencia' => (bool) $node->requiere_evidencia,
                        'es_calificable' => (bool) $node->es_calificable,
                        'puntaje_desde_hijos' => (bool) $node->puntaje_desde_hijos,
                        'tiene_hijos_calificables' => $hijos !== [],
                        'actividad_ids' => array_values(array_unique($actividadIds)),
                        'puede_calificar' => $puedeCalificar,
                        'asignado' => $scope['open'] || isset($scope['assigned_ids'][(int) $node->id]),
                    ];
                }
                $nextPath = $path === '' ? $node->name : $path.' › '.$node->name;
            } else {
                $nextPath = '';
            }

            foreach ($node->hijos ?? [] as $hijo) {
                $walk($hijo, $node->evento_padre_id ? $depth + 1 : 0, $nextPath);
            }
        };
        $walk($root, 0, '');

        return $out;
    }

    /**
     * @param  array{open: bool, assigned_ids: array<int, true>, visible_ids: array<int, true>}  $scope
     * @return list<array<string, mixed>>
     */
    private function calificableDescendants(Event $node, array $scope): array
    {
        $out = [];
        foreach ($node->hijos ?? [] as $hijo) {
            if (! $this->isVisibleInScope((int) $hijo->id, $scope)) {
                // Puede haber nietos visibles vía otra rama? No: si el hijo no es visible,
                // ningún descendiente debería serlo salvo que el hijo sea ancestro — y entonces sería visible.
                continue;
            }
            if ($this->canScoreInScope($hijo, $scope)) {
                $out[] = [
                    'id' => $hijo->id,
                    'name' => $hijo->name,
                    'image_url' => $hijo->image_url,
                    'puntaje_maximo' => $hijo->puntaje_maximo !== null ? (float) $hijo->puntaje_maximo : null,
                    'requiere_evidencia' => (bool) $hijo->requiere_evidencia,
                    'es_calificable' => true,
                    'puntaje_desde_hijos' => (bool) $hijo->puntaje_desde_hijos,
                    'puede_calificar' => true,
                ];
            }
            $out = array_merge($out, $this->calificableDescendants($hijo, $scope));
        }

        return $out;
    }

    /**
     * @param  list<array<string, mixed>>  $hijosCalificables
     * @param  array{open: bool, assigned_ids: array<int, true>, visible_ids: array<int, true>}  $scope
     * @return array<string, mixed>
     */
    private function mapSubevento(
        Event $sub,
        array $hijosCalificables = [],
        array $scope = ['open' => true, 'assigned_ids' => [], 'visible_ids' => []],
        ?Event $root = null,
    ): array {
        $tipo = $sub->relationLoaded('tipoEvento') ? $sub->tipoEvento : null;
        $categoria = $sub->relationLoaded('categoriaSubevento') ? $sub->categoriaSubevento : null;
        $puedeCalificar = $this->canScoreInScope($sub, $scope);

        [$juecesEfectivos, $juecesHeredados] = $this->effectiveStaffForNode($sub, 'jueces', $root);
        [$supervisoresEfectivos, $supervisoresHeredados] = $this->effectiveStaffForNode($sub, 'supervisores', $root);

        return [
            'id' => $sub->id,
            'name' => $sub->name,
            'descripcion' => $sub->descripcion,
            'reglas' => $sub->reglas,
            'estado' => $sub->estado,
            'image_url' => $sub->image_url,
            'starts_at' => $sub->starts_at?->toIso8601String(),
            'ends_at' => $sub->ends_at?->toIso8601String(),
            'puntaje_maximo' => $sub->puntaje_maximo !== null ? (float) $sub->puntaje_maximo : null,
            'requiere_evidencia' => (bool) $sub->requiere_evidencia,
            'tipos_evidencia' => array_values($sub->tipos_evidencia ?? []),
            'es_calificable' => (bool) $sub->es_calificable,
            'puntaje_desde_hijos' => (bool) $sub->puntaje_desde_hijos,
            'puntaje_por_participar' => (bool) $sub->puntaje_por_participar,
            'puede_calificar' => $puedeCalificar,
            'asignado' => $scope['open'] || isset($scope['assigned_ids'][(int) $sub->id]),
            'tiempo_estimado_minutos' => $sub->tiempo_estimado_minutos !== null
                ? (int) $sub->tiempo_estimado_minutos
                : null,
            'requiere_puesto_entrega' => (bool) $sub->requiere_puesto_entrega,
            'requiere_tiempo_entrega' => (bool) $sub->requiere_tiempo_entrega,
            'resultado_esperado' => $sub->resultado_esperado !== null ? (int) $sub->resultado_esperado : null,
            'participantes_min' => $sub->participantes_min !== null ? (int) $sub->participantes_min : null,
            'participantes_max' => $sub->participantes_max !== null ? (int) $sub->participantes_max : null,
            'permite_inscribir_no_participantes' => (bool) $sub->permite_inscribir_no_participantes,
            'participantes_genero' => $sub->participantes_genero,
            'participantes_min_m' => $sub->participantes_min_m !== null ? (int) $sub->participantes_min_m : null,
            'participantes_max_m' => $sub->participantes_max_m !== null ? (int) $sub->participantes_max_m : null,
            'participantes_min_f' => $sub->participantes_min_f !== null ? (int) $sub->participantes_min_f : null,
            'participantes_max_f' => $sub->participantes_max_f !== null ? (int) $sub->participantes_max_f : null,
            'es_conjunto' => (bool) $sub->es_conjunto,
            'nivel_conjunto' => $sub->nivel_conjunto,
            'maneja_fecha_fin' => (bool) $sub->maneja_fecha_fin,
            'maneja_penalizaciones' => (bool) $sub->maneja_penalizaciones,
            'puntos_penalizacion' => $sub->puntos_penalizacion !== null
                ? (float) $sub->puntos_penalizacion
                : null,
            'reglas_penalizacion' => $sub->reglas_penalizacion,
            'requiere_pago' => (bool) $sub->requiere_pago,
            'precio' => $sub->precio !== null ? (float) $sub->precio : null,
            'tipo_evento' => $tipo ? [
                'id' => (int) $tipo->id,
                'nombre' => $tipo->nombre,
                'slug' => $tipo->slug,
                'color' => $tipo->color,
                'icono' => $tipo->icono,
            ] : null,
            'categoria_subevento' => $categoria ? [
                'id' => (int) $categoria->id,
                'nombre' => $categoria->nombre,
                'slug' => $categoria->slug,
                'color' => $categoria->color,
                'icono' => $categoria->icono,
            ] : null,
            'jueces' => $juecesEfectivos,
            'jueces_heredados' => $juecesHeredados,
            'supervisores' => $supervisoresEfectivos,
            'supervisores_heredados' => $supervisoresHeredados,
            'criterios' => $sub->relationLoaded('criterios')
                ? $sub->criterios->map(fn ($c) => [
                    'id' => $c->id,
                    'nombre' => $c->nombre,
                    'descripcion' => $c->descripcion,
                    'puntos' => (float) $c->pivot->puntos,
                    'orden' => (int) $c->pivot->orden,
                ])->values()->all()
                : [],
            'hijos' => array_values($hijosCalificables),
        ];
    }

    /**
     * @return array{0: list<array<string, mixed>>, 1: bool}
     */
    private function effectiveStaffForNode(Event $node, string $relation, ?Event $root): array
    {
        if ($relation === 'jueces') {
            if ($root) {
                $inherited = $this->inheritedStaffFromAncestors($node, $root, 'jueces');
                [$users, $heredado] = $node->resolveEffectiveJueces($inherited);
            } else {
                [$users, $heredado] = $node->resolveEffectiveJueces();
            }
        } else {
            if ($root) {
                $inherited = $this->inheritedStaffFromAncestors($node, $root, 'supervisores');
                [$users, $heredado] = $node->resolveEffectiveSupervisores($inherited);
            } else {
                [$users, $heredado] = $node->resolveEffectiveSupervisores();
            }
        }

        $payload = $users->map(fn ($u) => [
            'id' => (int) $u->id,
            'name' => $u->name,
            'email' => $u->email,
        ])->values()->all();

        return [$payload, $heredado];
    }

    /**
     * @return Collection<int, User>|null
     */
    private function inheritedStaffFromAncestors(Event $node, Event $root, string $relation)
    {
        $parentId = $node->evento_padre_id ? (int) $node->evento_padre_id : null;
        if (! $parentId) {
            return null;
        }

        $current = $this->findInTree($root, $parentId);
        $guard = 0;
        while ($current && $guard < 20) {
            $own = $relation === 'jueces' ? $current->ownJueces() : $current->ownSupervisores();
            if ($own->isNotEmpty()) {
                return $own;
            }
            $nextId = $current->evento_padre_id ? (int) $current->evento_padre_id : null;
            if (! $nextId || (int) $root->id === $nextId) {
                // Subir al root si aplica.
                if ($nextId && (int) $root->id === $nextId) {
                    $ownRoot = $relation === 'jueces' ? $root->ownJueces() : $root->ownSupervisores();

                    return $ownRoot->isNotEmpty() ? $ownRoot : null;
                }

                return null;
            }
            $current = $this->findInTree($root, $nextId);
            $guard++;
        }

        return null;
    }

    /**
     * Clubes con evidencias en el alcance (actividad actual + resto del subárbol para conteo).
     * Solo incluye organizaciones dentro del rango del juez.
     *
     * @return list<array<string, mixed>>
     */
    private function clubsForScope(Event $branch, Event $actividad, User $actor): array
    {
        $scopeIds = $this->collectSubtreeIds($branch);
        if ($scopeIds === []) {
            $scopeIds = [(int) $branch->id];
        }

        $evidenciasScope = EventoEvidencia::query()
            ->whereIn('evento_id', $scopeIds)
            ->whereNotNull('organizacion_id')
            ->with('file:id,name,path,mime_type,size')
            ->orderByDesc('id')
            ->get();

        $rosterScope = EventoActividadParticipante::query()
            ->whereIn('evento_id', $scopeIds)
            ->whereNotNull('organizacion_id')
            ->with('persona:id,nombre1,nombre2,apellido1,apellido2,sexo')
            ->get();

        if ($evidenciasScope->isEmpty() && $rosterScope->isEmpty()) {
            return [];
        }

        $allowed = $this->judgeAllowedOrgLookup($actor);
        $orgIds = $evidenciasScope
            ->pluck('organizacion_id')
            ->merge($rosterScope->pluck('organizacion_id'))
            ->map(fn ($id) => (int) $id)
            ->unique()
            ->filter(fn (int $id) => $this->orgIdAllowed($id, $allowed))
            ->values()
            ->all();

        if ($orgIds === []) {
            return [];
        }

        $evidenciasScope = $evidenciasScope->filter(
            fn (EventoEvidencia $e) => $this->orgIdAllowed((int) $e->organizacion_id, $allowed)
        );

        $orgs = Organizacion::query()
            ->whereIn('id', $orgIds)
            ->get(['id', 'nombre'])
            ->keyBy('id');

        $logos = Club::query()
            ->whereIn('organizacion_id', $orgIds)
            ->pluck('logo', 'organizacion_id');

        $cals = EventoCalificacion::query()
            ->where('evento_id', $actividad->id)
            ->whereIn('organizacion_id', $orgIds)
            ->whereNull('persona_id')
            ->where('calificado_por', $actor->id)
            ->with('detalles')
            ->get()
            ->keyBy('organizacion_id');

        $obsDirector = EventoCalificacionObsDirector::query()
            ->where('evento_id', $actividad->id)
            ->whereIn('organizacion_id', $orgIds)
            ->get()
            ->keyBy(fn (EventoCalificacionObsDirector $o) => (int) $o->organizacion_id);

        /** @var Collection<int, Collection<int, EventoEvidencia>> $evByOrgActividad */
        $evByOrgActividad = $evidenciasScope
            ->where('evento_id', $actividad->id)
            ->groupBy('organizacion_id');

        /** @var Collection<int, Collection<int, EventoEvidencia>> $evByOrgAll */
        $evByOrgAll = $evidenciasScope->groupBy('organizacion_id');

        $rosterByOrgActividad = $rosterScope
            ->filter(fn (EventoActividadParticipante $row) => $this->orgIdAllowed((int) $row->organizacion_id, $allowed))
            ->where('evento_id', $actividad->id)
            ->groupBy('organizacion_id');

        $max = $actividad->puntaje_maximo !== null ? (float) $actividad->puntaje_maximo : null;
        $out = [];

        foreach ($orgIds as $orgId) {
            $org = $orgs->get($orgId);
            if (! $org) {
                continue;
            }

            $evActividad = $evByOrgActividad->get($orgId) ?? collect();
            $evAll = $evByOrgAll->get($orgId) ?? collect();
            $rosterActividad = $rosterByOrgActividad->get($orgId) ?? collect();
            $cal = $cals->get($orgId);
            $obs = $obsDirector->get($orgId);
            $score = $cal ? (float) $cal->puntaje_obtenido : null;
            $pct = $max && $score !== null ? (int) round(($score / $max) * 100) : null;
            $calPayload = $cal ? $this->calificacionPayload($cal) : null;
            if ($calPayload !== null) {
                $calPayload['observaciones_director'] = $obs?->observaciones;
                $calPayload['observaciones_director_updated_at'] = $obs?->updated_at?->toIso8601String();
            }

            $out[] = [
                'organizacion_id' => $orgId,
                'nombre' => $org->nombre,
                'logo_url' => $logos->get($orgId) ?: null,
                'estado' => $cal ? 'evaluado' : 'pendiente',
                'puntaje_obtenido' => $score,
                'puntaje_maximo' => $max,
                'porcentaje' => $pct,
                'evidencias_count' => $evAll->count(),
                'evidencias_en_actividad' => $evActividad->count(),
                'evidencias' => $evActividad
                    ->map(fn (EventoEvidencia $e) => $this->participation->evidenciaPayload($e))
                    ->values()
                    ->all(),
                'participantes' => $rosterActividad
                    ->map(function (EventoActividadParticipante $row) {
                        $persona = $row->persona;

                        return [
                            'id' => (int) $row->persona_id,
                            'nombre' => $persona?->full_name ?: ('#'.$row->persona_id),
                            'sexo' => $persona?->sexo,
                        ];
                    })
                    ->values()
                    ->all(),
                'calificacion' => $calPayload,
                'observaciones_director' => $obs?->observaciones,
                'observaciones_director_updated_at' => $obs?->updated_at?->toIso8601String(),
            ];
        }

        usort($out, function (array $a, array $b) {
            if ($a['estado'] !== $b['estado']) {
                return $a['estado'] === 'pendiente' ? -1 : 1;
            }

            return strcasecmp($a['nombre'], $b['nombre']);
        });

        return $out;
    }

    /**
     * Resumen de clubes para la fase 1 del juez (pendientes en todo su alcance).
     * Solo organizaciones dentro del rango del juez.
     *
     * @param  array<string, array<string, int>>  $pendientes
     * @param  array<string, array<string, float>>  $evaluados
     * @param  array<string, array<string, int>>  $evidencias
     * @param  array<int, true>|null  $allowedOrgLookup  null = sin filtro (bypass)
     * @return list<array<string, mixed>>
     */
    private function buildClubesResumen(
        array $pendientes,
        array $evaluados,
        array $evidencias,
        ?array $allowedOrgLookup,
    ): array {
        $orgIds = [];
        foreach (array_keys($pendientes) as $orgId) {
            $id = (int) $orgId;
            if ($this->orgIdAllowed($id, $allowedOrgLookup)) {
                $orgIds[$id] = true;
            }
        }
        foreach (array_keys($evaluados) as $orgId) {
            $id = (int) $orgId;
            if ($this->orgIdAllowed($id, $allowedOrgLookup)) {
                $orgIds[$id] = true;
            }
        }
        foreach (array_keys($evidencias) as $orgId) {
            $id = (int) $orgId;
            if ($this->orgIdAllowed($id, $allowedOrgLookup)) {
                $orgIds[$id] = true;
            }
        }

        if ($orgIds === []) {
            return [];
        }

        $ids = array_keys($orgIds);
        $orgs = Organizacion::query()
            ->whereIn('id', $ids)
            ->get(['id', 'nombre'])
            ->keyBy('id');

        $clubs = Club::query()
            ->whereIn('organizacion_id', $ids)
            ->get(['id', 'organizacion_id', 'nombre', 'logo'])
            ->keyBy('organizacion_id');

        $out = [];
        foreach ($ids as $orgId) {
            $org = $orgs->get($orgId);
            if (! $org) {
                continue;
            }
            $club = $clubs->get($orgId);
            $pendingByEvent = $pendientes[(string) $orgId] ?? [];
            $scoredByEvent = $evaluados[(string) $orgId] ?? [];
            $evidenceByEvent = $evidencias[(string) $orgId] ?? [];

            $eventosPendientes = count($pendingByEvent);
            $evidenciasPendientes = (int) array_sum($pendingByEvent);
            $eventosEvaluados = count($scoredByEvent);
            $evidenciasTotal = (int) array_sum($evidenceByEvent);

            $estado = $eventosPendientes > 0
                ? 'pendiente'
                : ($eventosEvaluados > 0 ? 'evaluado' : 'pendiente');

            $out[] = [
                'organizacion_id' => $orgId,
                'nombre' => $club?->nombre ?: $org->nombre,
                'logo_url' => $club?->logo ?: null,
                'estado' => $estado,
                'eventos_pendientes' => $eventosPendientes,
                'evidencias_pendientes' => $evidenciasPendientes,
                'eventos_evaluados' => $eventosEvaluados,
                'evidencias_count' => $evidenciasTotal,
            ];
        }

        usort($out, function (array $a, array $b) {
            $pa = (int) $a['eventos_pendientes'];
            $pb = (int) $b['eventos_pendientes'];
            if ($pa !== $pb) {
                return $pb <=> $pa;
            }
            if ($a['estado'] !== $b['estado']) {
                return $a['estado'] === 'pendiente' ? -1 : 1;
            }

            return strcasecmp((string) $a['nombre'], (string) $b['nombre']);
        });

        return $out;
    }

    /**
     * Lookup de organizaciones accesibles para el juez.
     * null = bypass (ve todas); array vacío = ninguna; array<id,true> = solo esas.
     *
     * @return array<int, true>|null
     */
    private function judgeAllowedOrgLookup(User $actor): ?array
    {
        if ($this->orgAccess->bypassesOrganizationScope($actor)) {
            return null;
        }

        return array_fill_keys($this->orgAccess->accessibleOrganizationIds($actor), true);
    }

    private function orgIdAllowed(int $orgId, ?array $allowedOrgLookup): bool
    {
        if ($allowedOrgLookup === null) {
            return true;
        }

        return isset($allowedOrgLookup[$orgId]);
    }

    /**
     * Filtra mapas organizacion_id => … dejando solo orgs en rango.
     *
     * @param  array<string, mixed>  $map
     * @param  array<int, true>|null  $allowedOrgLookup
     * @return array<string, mixed>
     */
    private function filterOrgKeyedMap(array $map, ?array $allowedOrgLookup): array
    {
        if ($allowedOrgLookup === null) {
            return $map;
        }

        $out = [];
        foreach ($map as $orgKey => $value) {
            if ($this->orgIdAllowed((int) $orgKey, $allowedOrgLookup)) {
                $out[(string) $orgKey] = $value;
            }
        }

        return $out;
    }

    /**
     * Árbol de subeventos relevantes para el juez (solo lectura / navegación).
     *
     * @param  array{open: bool, assigned_ids: array<int, true>, visible_ids: array<int, true>}  $scope
     * @return list<array<string, mixed>>
     */
    private function buildJudgeTree(Event $root, array $scope): array
    {
        $out = [];
        foreach ($root->hijos ?? [] as $hijo) {
            $mapped = $this->mapJudgeTreeNode($hijo, $scope);
            if ($mapped !== null) {
                $out[] = $mapped;
            }
        }

        return $out;
    }

    /**
     * @param  array{open: bool, assigned_ids: array<int, true>, visible_ids: array<int, true>}  $scope
     * @return array<string, mixed>|null
     */
    private function mapJudgeTreeNode(Event $node, array $scope): ?array
    {
        $hijos = [];
        foreach ($node->hijos ?? [] as $hijo) {
            $mapped = $this->mapJudgeTreeNode($hijo, $scope);
            if ($mapped !== null) {
                $hijos[] = $mapped;
            }
        }

        if (! $this->isVisibleInScope((int) $node->id, $scope)) {
            return null;
        }

        $puedeCalificar = $this->canScoreInScope($node, $scope);
        $asignado = $scope['open'] || isset($scope['assigned_ids'][(int) $node->id]);

        // En modo abierto se ocultan hojas no calificables sin hijos útiles.
        if ($scope['open'] && ! $node->es_calificable && $hijos === []) {
            return null;
        }

        // Ancestro de contexto: visible aunque no sea calificable.
        if (! $puedeCalificar && ! $asignado && $hijos === [] && ! $node->es_calificable) {
            // Solo ancestro vacío no debería ocurrir; si ocurre, aún mostrarlo si está en visible.
            if (! isset($scope['visible_ids'][(int) $node->id])) {
                return null;
            }
        }

        $categoria = $node->relationLoaded('categoriaSubevento') ? $node->categoriaSubevento : null;
        $tipo = $node->relationLoaded('tipoEvento') ? $node->tipoEvento : null;

        return [
            'id' => (int) $node->id,
            'name' => $node->name,
            'image_url' => $node->image_url,
            'puntaje_maximo' => $node->puntaje_maximo !== null ? (float) $node->puntaje_maximo : null,
            'es_calificable' => (bool) $node->es_calificable,
            'requiere_evidencia' => (bool) $node->requiere_evidencia,
            'puede_calificar' => $puedeCalificar,
            'asignado' => $asignado,
            'icono' => $categoria?->icono ?: ($tipo?->icono ?: 'pi pi-flag'),
            'color' => $categoria?->color ?: ($tipo?->color ?: null),
            'categoria' => $categoria?->nombre,
            'tipo' => $tipo?->nombre,
            'hijos' => $hijos,
        ];
    }

    /**
     * Total de evidencias cargadas por club y actividad.
     *
     * @param  list<int>  $eventIds
     * @return array<string, array<string, int>> organizacion_id => [evento_id => count]
     */
    private function buildEvidenciasMap(array $eventIds): array
    {
        if ($eventIds === []) {
            return [];
        }

        $rows = EventoEvidencia::query()
            ->whereIn('evento_id', $eventIds)
            ->whereNotNull('organizacion_id')
            ->selectRaw('organizacion_id, evento_id, COUNT(*) as total')
            ->groupBy('organizacion_id', 'evento_id')
            ->get();

        $out = [];
        foreach ($rows as $row) {
            $orgId = (string) (int) $row->organizacion_id;
            $eventoId = (string) (int) $row->evento_id;
            $count = (int) $row->total;
            if ($count <= 0) {
                continue;
            }
            if (! isset($out[$orgId])) {
                $out[$orgId] = [];
            }
            $out[$orgId][$eventoId] = $count;
        }

        return $out;
    }

    /**
     * Calificaciones existentes por club y actividad.
     *
     * @param  list<int>  $eventIds
     * @return array<string, array<string, float>> organizacion_id => [evento_id => puntaje]
     */
    private function buildEvaluadosMap(array $eventIds, User $actor): array
    {
        if ($eventIds === []) {
            return [];
        }

        $calificados = EventoCalificacion::query()
            ->whereIn('evento_id', $eventIds)
            ->whereNotNull('organizacion_id')
            ->whereNull('persona_id')
            ->where('calificado_por', $actor->id)
            ->get(['organizacion_id', 'evento_id', 'puntaje_obtenido']);

        $out = [];
        foreach ($calificados as $cal) {
            $orgId = (string) (int) $cal->organizacion_id;
            $eventoId = (string) (int) $cal->evento_id;
            if (! isset($out[$orgId])) {
                $out[$orgId] = [];
            }
            $out[$orgId][$eventoId] = (float) $cal->puntaje_obtenido;
        }

        return $out;
    }

    /**
     * Evidencias pendientes de calificar por club y actividad.
     *
     * @param  list<int>  $eventIds
     * @return array<string, array<string, int>> organizacion_id => [evento_id => count]
     */
    private function buildPendientesMap(array $eventIds, User $actor): array
    {
        if ($eventIds === []) {
            return [];
        }

        $evidenciaCounts = EventoEvidencia::query()
            ->whereIn('evento_id', $eventIds)
            ->whereNotNull('organizacion_id')
            ->selectRaw('organizacion_id, evento_id, COUNT(*) as total')
            ->groupBy('organizacion_id', 'evento_id')
            ->get();

        $rosterCounts = EventoActividadParticipante::query()
            ->whereIn('evento_id', $eventIds)
            ->whereNotNull('organizacion_id')
            ->selectRaw('organizacion_id, evento_id, COUNT(*) as total')
            ->groupBy('organizacion_id', 'evento_id')
            ->get();

        if ($evidenciaCounts->isEmpty() && $rosterCounts->isEmpty()) {
            return [];
        }

        $calificados = EventoCalificacion::query()
            ->whereIn('evento_id', $eventIds)
            ->whereNotNull('organizacion_id')
            ->whereNull('persona_id')
            ->where('calificado_por', $actor->id)
            ->get(['organizacion_id', 'evento_id']);

        $calificadoSet = [];
        foreach ($calificados as $cal) {
            $calificadoSet[(int) $cal->organizacion_id.':'.(int) $cal->evento_id] = true;
        }

        $out = [];
        foreach ($evidenciaCounts as $row) {
            $orgId = (int) $row->organizacion_id;
            $eventoId = (int) $row->evento_id;
            $key = $orgId.':'.$eventoId;
            if (isset($calificadoSet[$key])) {
                continue;
            }
            $count = (int) $row->total;
            if ($count <= 0) {
                continue;
            }
            if (! isset($out[(string) $orgId])) {
                $out[(string) $orgId] = [];
            }
            $out[(string) $orgId][(string) $eventoId] = $count;
        }

        foreach ($rosterCounts as $row) {
            $orgId = (int) $row->organizacion_id;
            $eventoId = (int) $row->evento_id;
            $key = $orgId.':'.$eventoId;
            if (isset($calificadoSet[$key])) {
                continue;
            }
            if ((int) $row->total <= 0) {
                continue;
            }
            if (! isset($out[(string) $orgId])) {
                $out[(string) $orgId] = [];
            }
            if (! isset($out[(string) $orgId][(string) $eventoId])) {
                $out[(string) $orgId][(string) $eventoId] = 1;
            }
        }

        return $out;
    }

    /**
     * @return list<int>
     */
    private function collectSubtreeIds(Event $node): array
    {
        $ids = [(int) $node->id];
        foreach ($node->hijos ?? [] as $hijo) {
            $ids = array_merge($ids, $this->collectSubtreeIds($hijo));
        }

        return $ids;
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

    private function normalizeTiempoEntrega(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }
        $text = trim((string) $value);
        if (preg_match('/^(\d{1,3}):([0-5]\d)\.(\d{2})$/', $text, $m)) {
            return ((int) $m[1]).':'.$m[2].'.'.$m[3];
        }

        return null;
    }

    /**
     * @return array<string, mixed>
     */
    private function calificacionPayload(EventoCalificacion $cal): array
    {
        return [
            'id' => $cal->id,
            'evento_id' => $cal->evento_id,
            'organizacion_id' => $cal->organizacion_id,
            'puntaje_obtenido' => (float) $cal->puntaje_obtenido,
            'observaciones' => $cal->observaciones,
            'puesto_entrega' => $cal->puesto_entrega,
            'tiempo_entrega' => $this->normalizeTiempoEntrega($cal->tiempo_entrega),
            'resultado_obtenido' => $cal->resultado_obtenido !== null ? (int) $cal->resultado_obtenido : null,
            'calificado_por' => $cal->calificado_por,
            'detalles' => ($cal->relationLoaded('detalles') ? $cal->detalles : $cal->detalles()->get())
                ->map(fn (EventoCalificacionDetalle $d) => [
                    'criterio_evaluacion_id' => (int) $d->criterio_evaluacion_id,
                    'puntos' => (float) $d->puntos,
                ])->values()->all(),
            'updated_at' => $cal->updated_at?->toIso8601String(),
        ];
    }

    private function eagerLoadTree(Event $event, int $depth): void
    {
        if ($depth <= 0) {
            return;
        }

        $event->load([
            'hijos' => fn ($q) => $q->orderBy('orden')->orderBy('id'),
            'categoriaSubevento:id,nombre,slug,color,icono',
            'tipoEvento:id,nombre,slug,color,icono',
            'jueces:id,name,email',
            'supervisores:id,name,email',
        ]);

        foreach ($event->hijos as $hijo) {
            $this->eagerLoadTree($hijo, $depth - 1);
        }
    }

    /**
     * @return array{id: int, name: string, image_url: ?string, banner_url: ?string, estado: mixed}
     */
    private function eventHeader(Event $root): array
    {
        return [
            'id' => $root->id,
            'name' => $root->name,
            'image_url' => $root->image_url,
            'banner_url' => $root->banner_url,
            'estado' => $root->estado,
        ];
    }
}
