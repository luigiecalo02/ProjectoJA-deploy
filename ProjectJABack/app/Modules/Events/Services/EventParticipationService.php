<?php

namespace App\Modules\Events\Services;

use App\Models\User;
use App\Modules\Clubs\Models\Club;
use App\Modules\Events\Models\Event;
use App\Modules\Events\Models\EventoCalificacion;
use App\Modules\Events\Models\EventoCalificacionObsDirector;
use App\Modules\Events\Models\EventoEvidencia;
use App\Modules\Events\Models\EventoInscripcion;
use App\Modules\Organizations\Models\Organizacion;
use App\Modules\Organizations\Services\OrganizationAccessService;
use App\Modules\Shared\Models\StoredFile;
use App\Modules\Shared\Services\ImageOptimizer;
use App\Modules\Users\Models\Role;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

/**
 * Participación e inscripción de clubes.
 * Panel de jueces: ver EventJudgeService.
 */
final class EventParticipationService
{
    private const CLUB_TIPOS = [
        Organizacion::TIPO_CLUB,
        Organizacion::TIPO_AVENTUREROS,
        Organizacion::TIPO_CONQUISTADORES,
        Organizacion::TIPO_GUIAS_MAYORES,
    ];

    private const DIRECTOR_ROLES = ['director', 'subdirector'];

    public function __construct(
        private readonly OrganizationAccessService $orgAccess,
        private readonly EventCalificacionAggregator $calificacionAggregator,
        private readonly ImageOptimizer $imageOptimizer,
    ) {}

    /**
     * @return array{organizacion_id: int, organizacion: Organizacion, role: string}
     */
    public function assertClubDirectorContext(User $actor): array
    {
        $orgId = $actor->active_organizacion_id ? (int) $actor->active_organizacion_id : null;
        if (! $orgId) {
            throw new AccessDeniedHttpException('Selecciona un contexto de club para continuar.');
        }

        $org = Organizacion::query()->find($orgId);
        if (! $org || ! in_array((int) $org->tipo_organizacion_id, self::CLUB_TIPOS, true)) {
            throw new AccessDeniedHttpException('Esta acción solo está disponible en contexto de club.');
        }

        $role = $this->activeRoleName($actor);
        if (! in_array($role, self::DIRECTOR_ROLES, true) && ! $actor->isPlatformAdmin()) {
            throw new AccessDeniedHttpException('Solo directores o subdirectores de club pueden realizar esta acción.');
        }

        if (! $this->orgAccess->bypassesOrganizationScope($actor)) {
            $accessible = $this->orgAccess->accessibleOrganizationIds($actor);
            if (! in_array($orgId, $accessible, true)) {
                throw new AccessDeniedHttpException('No tienes acceso a esta organización.');
            }
        }

        return [
            'organizacion_id' => $orgId,
            'organizacion' => $org,
            'role' => $role ?? 'director',
        ];
    }

    public function findRootInscripcion(Event $root, int $organizacionId): ?EventoInscripcion
    {
        return EventoInscripcion::query()
            ->where('evento_id', $root->id)
            ->where('tipo', 'club')
            ->where('organizacion_id', $organizacionId)
            ->first();
    }

    public function enrollClub(User $actor, Event $event): EventoInscripcion
    {
        $ctx = $this->assertClubDirectorContext($actor);
        $root = $this->resolveRoot($event);

        if (! $actor->can('view', $root)) {
            throw new AccessDeniedHttpException('No puedes ver este evento.');
        }

        if ($root->estado === Event::ESTADO_CANCELADO) {
            throw ValidationException::withMessages([
                'evento' => ['Este evento está cancelado.'],
            ]);
        }

        if (! $root->permite_inscripcion_club) {
            throw ValidationException::withMessages([
                'evento' => ['Este evento no permite inscripción de clubes.'],
            ]);
        }

        return DB::transaction(function () use ($actor, $root, $ctx) {
            $inscripcion = EventoInscripcion::query()->updateOrCreate(
                [
                    'evento_id' => $root->id,
                    'tipo' => 'club',
                    'organizacion_id' => $ctx['organizacion_id'],
                ],
                [
                    'persona_id' => null,
                    'estado' => EventoInscripcion::ESTADO_PENDIENTE_REVISION,
                    'inscrito_por' => $actor->id,
                ]
            );

            $this->applyInscripcionScore($root, $ctx['organizacion_id']);

            return $inscripcion->fresh();
        });
    }

    public function applyInscripcionScore(Event $root, int $organizacionId): EventoCalificacion
    {
        [$puntos, $obs] = $this->resolveInscripcionPoints($root);

        return EventoCalificacion::query()->updateOrCreate(
            [
                'evento_id' => $root->id,
                'organizacion_id' => $organizacionId,
                'persona_id' => null,
                'calificado_por' => null,
            ],
            [
                'puntaje_obtenido' => $puntos,
                'observaciones' => $obs,
            ]
        );
    }

    /**
     * @return array{0: float, 1: string}
     */
    public function resolveInscripcionPoints(Event $root): array
    {
        $limite = $root->fecha_limite_inscripcion;
        $aTiempo = $root->puntos_inscripcion_a_tiempo !== null
            ? (float) $root->puntos_inscripcion_a_tiempo
            : 0.0;
        $fuera = $root->puntos_inscripcion_fuera_tiempo !== null
            ? (float) $root->puntos_inscripcion_fuera_tiempo
            : 0.0;

        if (! $limite) {
            return [$aTiempo, 'Inscripción a tiempo'];
        }

        if (Carbon::now()->lte($limite)) {
            return [$aTiempo, 'Inscripción a tiempo'];
        }

        return [$fuera, 'Inscripción fuera de tiempo'];
    }

    /**
     * @return array<string, mixed>
     */
    public function participationPayload(User $actor, Event $event): array
    {
        $ctx = $this->assertClubDirectorContext($actor);
        $root = $this->resolveRoot($event);

        if (! $actor->can('view', $root)) {
            throw new AccessDeniedHttpException('No puedes ver este evento.');
        }

        $inscripcion = $this->findRootInscripcion($root, $ctx['organizacion_id']);

        $root->loadMissing([
            'criterios',
            'hijos' => fn ($q) => $q->orderBy('orden')->orderBy('id'),
        ]);
        $this->eagerLoadTree($root, 6);

        $orgId = $ctx['organizacion_id'];
        $eventoIds = $this->collectEventIds($root);
        $calRows = EventoCalificacion::query()
            ->whereIn('evento_id', $eventoIds)
            ->where('organizacion_id', $orgId)
            ->whereNull('persona_id')
            ->with('detalles')
            ->orderBy('id')
            ->get();

        $calificaciones = $this->indexAggregatedCalificaciones($calRows, (int) $root->id);
        $obsDirector = EventoCalificacionObsDirector::query()
            ->where('organizacion_id', $orgId)
            ->whereIn('evento_id', $eventoIds)
            ->get()
            ->keyBy(fn (EventoCalificacionObsDirector $o) => (int) $o->evento_id);

        $calificaciones = $calificaciones->map(function (array $payload, int $eventoId) use ($obsDirector) {
            $obs = $obsDirector->get($eventoId);
            $payload['observaciones_director'] = $obs?->observaciones;
            $payload['observaciones_director_updated_at'] = $obs?->updated_at?->toIso8601String();

            return $payload;
        });

        $evidencias = EventoEvidencia::query()
            ->whereIn('evento_id', $eventoIds)
            ->where('organizacion_id', $orgId)
            ->with('file:id,name,path,mime_type,size')
            ->orderByDesc('id')
            ->get()
            ->groupBy('evento_id');

        $tree = $this->mapNode($root, $calificaciones, $evidencias, true);
        $progreso = $this->buildProgress($root, $calificaciones);

        $clubLogo = Club::query()
            ->where('organizacion_id', $orgId)
            ->value('logo');

        return [
            'evento' => $tree,
            'inscripcion' => $inscripcion
                ? [
                    'id' => $inscripcion->id,
                    'estado' => $inscripcion->estado,
                    'tipo' => $inscripcion->tipo,
                    'organizacion_id' => (int) $inscripcion->organizacion_id,
                    'total_declarado' => $inscripcion->total_declarado,
                    'observacion_revision' => $inscripcion->observacion_revision,
                    'puede_elegir_lote' => $inscripcion->estaAprobada(),
                    'created_at' => $inscripcion->created_at?->toIso8601String(),
                ]
                : null,
            'organizacion' => [
                'id' => $ctx['organizacion']->id,
                'nombre' => $ctx['organizacion']->nombre,
                'logo_url' => $clubLogo ?: null,
            ],
            'progreso' => $progreso,
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function createEvidencia(
        User $actor,
        Event $subevento,
        array $data,
        ?UploadedFile $archivo = null,
    ): EventoEvidencia {
        $ctx = $this->assertClubDirectorContext($actor);
        $root = $this->resolveRoot($subevento);
        $inscripcion = $this->findRootInscripcion($root, $ctx['organizacion_id']);

        if (! $actor->can('view', $root)) {
            throw new AccessDeniedHttpException('No puedes ver este evento.');
        }

        if (! $subevento->requiere_evidencia) {
            throw ValidationException::withMessages([
                'evento' => ['Este subevento no requiere evidencia.'],
            ]);
        }

        $tipo = (string) ($data['tipo'] ?? '');
        $permitidos = array_values($subevento->tipos_evidencia ?? []);
        if (! in_array($tipo, $permitidos, true)) {
            throw ValidationException::withMessages([
                'tipo' => ['Tipo de evidencia no permitido para este subevento.'],
            ]);
        }

        $url = isset($data['url']) ? trim((string) $data['url']) : null;
        $fileId = isset($data['file_id']) ? (int) $data['file_id'] : null;

        if ($tipo === 'link') {
            if (! $url) {
                throw ValidationException::withMessages([
                    'url' => ['La URL es obligatoria para evidencias de tipo link.'],
                ]);
            }
            $fileId = null;
            $archivo = null;
        } else {
            if ($archivo) {
                $this->assertArchivoMatchesTipo($archivo, $tipo);
                $stored = $this->storeEvidenciaArchivo($subevento, $archivo, $actor);
                $fileId = (int) $stored->id;
                $url = url('storage/'.$stored->path);
            } elseif ($fileId) {
                StoredFile::query()->findOrFail($fileId);
            } elseif (! $url) {
                throw ValidationException::withMessages([
                    'archivo' => ['Adjunta un archivo o indica una URL para esta evidencia.'],
                ]);
            }
        }

        return EventoEvidencia::query()->create([
            'evento_id' => $subevento->id,
            'organizacion_id' => $ctx['organizacion_id'],
            'persona_id' => null,
            'inscripcion_id' => $inscripcion?->id,
            'tipo' => $tipo,
            'titulo' => isset($data['titulo']) ? (trim((string) $data['titulo']) ?: null) : null,
            'descripcion' => isset($data['descripcion']) ? (trim((string) $data['descripcion']) ?: null) : null,
            'url' => $url ?: null,
            'file_id' => $fileId ?: null,
            'subido_por' => $actor->id,
            'estado' => $data['estado'] ?? EventoEvidencia::ESTADO_ENVIADA,
        ])->load('file:id,name,path,mime_type,size');
    }

    /**
     * Observación del director de club sobre la calificación de los jueces.
     *
     * @return array<string, mixed>
     */
    public function saveDirectorObservacion(User $actor, Event $subevento, string $observaciones): array
    {
        $ctx = $this->assertClubDirectorContext($actor);
        $root = $this->resolveRoot($subevento);

        if (! $actor->can('view', $root)) {
            throw new AccessDeniedHttpException('No puedes ver este evento.');
        }

        if ($subevento->puntaje_desde_hijos) {
            throw ValidationException::withMessages([
                'evento' => ['Deja la observación en la actividad calificada, no en el nodo resumen.'],
            ]);
        }

        if (! $subevento->es_calificable) {
            throw ValidationException::withMessages([
                'evento' => ['Solo puedes comentar actividades calificables.'],
            ]);
        }

        $orgId = $ctx['organizacion_id'];
        $hasScore = EventoCalificacion::query()
            ->where('evento_id', $subevento->id)
            ->where('organizacion_id', $orgId)
            ->whereNull('persona_id')
            ->exists();

        if (! $hasScore) {
            throw ValidationException::withMessages([
                'observaciones' => ['Aún no hay calificación de jueces para comentar.'],
            ]);
        }

        $texto = trim($observaciones);
        if ($texto === '') {
            throw ValidationException::withMessages([
                'observaciones' => ['La observación no puede estar vacía.'],
            ]);
        }

        $row = EventoCalificacionObsDirector::query()->updateOrCreate(
            [
                'evento_id' => $subevento->id,
                'organizacion_id' => $orgId,
            ],
            [
                'observaciones' => $texto,
                'creado_por' => $actor->id,
            ],
        );

        return [
            'evento_id' => (int) $row->evento_id,
            'organizacion_id' => (int) $row->organizacion_id,
            'observaciones_director' => $row->observaciones,
            'observaciones_director_updated_at' => $row->updated_at?->toIso8601String(),
        ];
    }

    private function assertArchivoMatchesTipo(UploadedFile $archivo, string $tipo): void
    {
        $mime = strtolower((string) ($archivo->getMimeType() ?: ''));
        $name = strtolower($archivo->getClientOriginalName());
        $ext = pathinfo($name, PATHINFO_EXTENSION);

        $ok = match ($tipo) {
            'pdf' => str_contains($mime, 'pdf') || $ext === 'pdf',
            'imagen' => str_starts_with($mime, 'image/')
                || in_array($ext, ['jpg', 'jpeg', 'png', 'webp', 'gif', 'bmp'], true),
            'audio' => str_starts_with($mime, 'audio/')
                || in_array($ext, ['mp3', 'wav', 'ogg', 'm4a', 'aac', 'flac', 'webm', 'oga', 'opus'], true)
                || (in_array($mime, ['application/octet-stream', 'binary/octet-stream'], true)
                    && in_array($ext, ['mp3', 'wav', 'ogg', 'm4a', 'aac', 'flac', 'webm', 'oga', 'opus'], true)),
            'video' => str_starts_with($mime, 'video/')
                || in_array($ext, ['mp4', 'webm', 'mov', 'avi', 'mkv', 'm4v'], true)
                || (in_array($mime, ['application/octet-stream', 'binary/octet-stream'], true)
                    && in_array($ext, ['mp4', 'webm', 'mov', 'avi', 'mkv', 'm4v'], true)),
            default => false,
        };

        if (! $ok) {
            throw ValidationException::withMessages([
                'archivo' => ["El archivo no corresponde al tipo de evidencia \"{$tipo}\"."],
            ]);
        }
    }

    private function storeEvidenciaArchivo(Event $subevento, UploadedFile $archivo, User $actor): StoredFile
    {
        $stored = $this->imageOptimizer->store($archivo, "evidencias/{$subevento->id}", 'evd');

        return StoredFile::query()->create([
            'name' => $archivo->getClientOriginalName(),
            'path' => $stored->path,
            'size' => $stored->size,
            'mime_type' => $stored->mime,
            'hash' => $stored->hash,
            'uploaded_by' => $actor->id,
        ]);
    }

    public function deleteEvidencia(User $actor, EventoEvidencia $evidencia): void
    {
        $ctx = $this->assertClubDirectorContext($actor);
        if ((int) $evidencia->organizacion_id !== $ctx['organizacion_id']) {
            throw new AccessDeniedHttpException('No puedes eliminar esta evidencia.');
        }

        $evidencia->delete();
    }

    /**
     * Enrollment status for list cards.
     *
     * @param  list<int>  $eventIds
     * @return array<int, bool>
     */
    public function enrolledMap(User $actor, array $eventIds): array
    {
        if ($eventIds === []) {
            return [];
        }

        try {
            $ctx = $this->assertClubDirectorContext($actor);
        } catch (\Throwable) {
            return [];
        }

        $rows = EventoInscripcion::query()
            ->whereIn('evento_id', $eventIds)
            ->where('tipo', 'club')
            ->where('organizacion_id', $ctx['organizacion_id'])
            ->pluck('evento_id')
            ->map(fn ($id) => (int) $id)
            ->all();

        $map = [];
        foreach ($eventIds as $id) {
            $map[(int) $id] = in_array((int) $id, $rows, true);
        }

        return $map;
    }

    public function resolveRoot(Event $event): Event
    {
        $current = $event;
        $guard = 0;
        while ($current->evento_padre_id && $guard < 20) {
            $current = $current->padre ?? Event::query()->findOrFail($current->evento_padre_id);
            $guard++;
        }

        return $current;
    }

    private function activeRoleName(User $actor): ?string
    {
        if (! $actor->active_rol_id) {
            return null;
        }

        $role = Role::query()->find($actor->active_rol_id);

        return $role?->name;
    }

    private function eagerLoadTree(Event $event, int $depth): void
    {
        if ($depth <= 0) {
            return;
        }

        $event->loadMissing([
            'criterios' => fn ($q) => $q->orderByPivot('orden'),
            'tipoEvento:id,nombre,slug,color,icono',
            'categoriaSubevento:id,nombre,slug,color,icono,maneja_puntos,maneja_fecha_inicio,maneja_fecha_fin',
            'jueces:id,name,email',
            'supervisores:id,name,email',
            'hijos' => fn ($q) => $q->orderBy('orden')->orderBy('id'),
        ]);

        foreach ($event->hijos as $hijo) {
            $this->eagerLoadTree($hijo, $depth - 1);
        }
    }

    /**
     * @return list<int>
     */
    private function collectEventIds(Event $event): array
    {
        $ids = [(int) $event->id];
        foreach ($event->hijos ?? [] as $hijo) {
            $ids = array_merge($ids, $this->collectEventIds($hijo));
        }

        return $ids;
    }

    /**
     * @param  Collection<int, array<string, mixed>>  $calificaciones
     * @param  Collection<int, Collection<int, EventoEvidencia>>  $evidencias
     * @return array<string, mixed>
     */
    private function mapNode(Event $event, $calificaciones, $evidencias, bool $isRoot): array
    {
        $hijos = ($event->hijos ?? collect())
            ->map(fn (Event $hijo) => $this->mapNode($hijo, $calificaciones, $evidencias, false))
            ->values()
            ->all();

        $calPayload = $calificaciones->get((int) $event->id);
        $evs = $evidencias->get($event->id) ?? $evidencias->get((int) $event->id) ?? collect();
        $tipo = $event->relationLoaded('tipoEvento') ? $event->tipoEvento : null;
        $categoria = $event->relationLoaded('categoriaSubevento') ? $event->categoriaSubevento : null;

        $calificacion = is_array($calPayload)
            ? $calPayload
            : ($event->puntaje_desde_hijos ? $this->rollupCalificacionFromChildren($hijos) : null);

        return [
            'id' => $event->id,
            'name' => $event->name,
            'descripcion' => $event->descripcion,
            'reglas' => $event->reglas,
            'estado' => $event->estado,
            'image_url' => $event->image_url,
            'evento_padre_id' => $event->evento_padre_id,
            'es_calificable' => (bool) $event->es_calificable,
            'puntaje_maximo' => $event->puntaje_maximo !== null ? (float) $event->puntaje_maximo : null,
            'puntaje_desde_hijos' => (bool) $event->puntaje_desde_hijos,
            'requiere_evidencia' => (bool) $event->requiere_evidencia,
            'tipos_evidencia' => array_values($event->tipos_evidencia ?? []),
            'maneja_fecha_fin' => (bool) $event->maneja_fecha_fin,
            'maneja_penalizaciones' => (bool) $event->maneja_penalizaciones,
            'puntos_penalizacion' => $event->puntos_penalizacion !== null
                ? (float) $event->puntos_penalizacion
                : null,
            'reglas_penalizacion' => $event->reglas_penalizacion,
            'tiempo_estimado_minutos' => $event->tiempo_estimado_minutos !== null
                ? (int) $event->tiempo_estimado_minutos
                : null,
            'participantes_min' => $event->participantes_min !== null ? (int) $event->participantes_min : null,
            'participantes_max' => $event->participantes_max !== null ? (int) $event->participantes_max : null,
            'es_conjunto' => (bool) $event->es_conjunto,
            'nivel_conjunto' => $event->nivel_conjunto,
            'requiere_pago' => (bool) $event->requiere_pago,
            'precio' => $event->precio !== null ? (float) $event->precio : null,
            'starts_at' => $event->starts_at?->toIso8601String(),
            'ends_at' => $event->ends_at?->toIso8601String(),
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
            'jueces' => $event->relationLoaded('jueces')
                ? $event->jueces->map(fn ($u) => [
                    'id' => (int) $u->id,
                    'name' => $u->name,
                    'email' => $u->email,
                ])->values()->all()
                : [],
            'supervisores' => $event->relationLoaded('supervisores')
                ? $event->supervisores->map(fn ($u) => [
                    'id' => (int) $u->id,
                    'name' => $u->name,
                    'email' => $u->email,
                ])->values()->all()
                : [],
            'criterios' => $event->relationLoaded('criterios')
                ? $event->criterios->map(fn ($c) => [
                    'id' => $c->id,
                    'nombre' => $c->nombre,
                    'descripcion' => $c->descripcion,
                    'puntos' => (float) $c->pivot->puntos,
                    'orden' => (int) $c->pivot->orden,
                ])->values()->all()
                : [],
            'calificacion' => $calificacion,
            'evidencias' => $evs->map(fn (EventoEvidencia $e) => $this->evidenciaPayload($e))->values()->all(),
            'is_root' => $isRoot,
            'hijos' => $hijos,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function calificacionNodePayload(EventoCalificacion $cal): array
    {
        return $this->calificacionAggregator->singlePayload($cal, false);
    }

    /**
     * @param  Collection<int, EventoCalificacion>  $rows
     * @return Collection<int, array<string, mixed>>
     */
    private function indexAggregatedCalificaciones($rows, int $rootId)
    {
        return $rows
            ->groupBy(fn (EventoCalificacion $c) => (int) $c->evento_id)
            ->map(function ($group, $eventoId) use ($rootId) {
                $group = collect($group)->values();
                if ((int) $eventoId === $rootId) {
                    $inscripcion = $group->first(fn (EventoCalificacion $c) => $c->calificado_por === null)
                        ?? $group->sortByDesc('id')->first();

                    return $inscripcion
                        ? $this->calificacionAggregator->singlePayload($inscripcion, false)
                        : null;
                }

                return $this->calificacionAggregator->averagePayload($group);
            })
            ->filter()
            ->mapWithKeys(fn ($payload, $eventoId) => [(int) $eventoId => $payload]);
    }

    /**
     * Suma las calificaciones de hojas (o rollups hijos) para padres con puntaje_desde_hijos.
     *
     * @param  list<array<string, mixed>>  $hijos
     * @return array<string, mixed>|null
     */
    private function rollupCalificacionFromChildren(array $hijos): ?array
    {
        $sum = 0.0;
        $scoredLeaves = 0;
        $totalLeaves = 0;
        $latest = null;

        $walk = function (array $node) use (&$walk, &$sum, &$scoredLeaves, &$totalLeaves, &$latest): void {
            $childNodes = is_array($node['hijos'] ?? null) ? $node['hijos'] : [];
            $isAggregator = ! empty($node['puntaje_desde_hijos']);
            $isLeafScoreable = ! empty($node['es_calificable']) && ! $isAggregator;

            if ($isLeafScoreable) {
                $totalLeaves++;
                $cal = is_array($node['calificacion'] ?? null) ? $node['calificacion'] : null;
                if ($cal) {
                    $scoredLeaves++;
                    $sum += (float) ($cal['puntaje_obtenido'] ?? 0);
                    $updated = $cal['updated_at'] ?? null;
                    if ($updated && ($latest === null || strcmp((string) $updated, (string) $latest) > 0)) {
                        $latest = $updated;
                    }
                }

                return;
            }

            foreach ($childNodes as $hijo) {
                if (is_array($hijo)) {
                    $walk($hijo);
                }
            }
        };

        foreach ($hijos as $hijo) {
            $walk($hijo);
        }

        if ($scoredLeaves <= 0) {
            return null;
        }

        $parcial = $scoredLeaves < $totalLeaves;

        return [
            'id' => null,
            'puntaje_obtenido' => round($sum, 2),
            'observaciones' => $parcial
                ? 'Parcial: suma de subeventos calificados'
                : 'Suma de subeventos',
            'calificado_por' => null,
            'updated_at' => $latest,
            'es_agregado' => true,
            'es_promedio' => false,
            'jueces_count' => 0,
            'aportes' => [],
            'observaciones_director' => null,
            'observaciones_director_updated_at' => null,
            'detalles' => [],
        ];
    }

    /**
     * @param  Collection<int, array<string, mixed>>  $calificaciones
     * @return array<string, mixed>
     */
    private function buildProgress(Event $root, $calificaciones): array
    {
        $inscripcionPts = 0.0;
        $rootCal = $calificaciones->get((int) $root->id);
        if (is_array($rootCal)) {
            $inscripcionPts = (float) ($rootCal['puntaje_obtenido'] ?? 0);
        }

        $subPts = 0.0;
        $subMax = 0.0;
        $this->accumulateSubProgress($root, $calificaciones, $subPts, $subMax);

        $inscripcionMax = max(
            (float) ($root->puntos_inscripcion_a_tiempo ?? 0),
            (float) ($root->puntos_inscripcion_fuera_tiempo ?? 0),
        );

        return [
            'puntos_inscripcion' => $inscripcionPts,
            'puntos_inscripcion_max' => $inscripcionMax,
            'puntos_subeventos' => $subPts,
            'puntos_subeventos_max' => $subMax,
            'puntos_total' => $inscripcionPts + $subPts,
            'puntos_total_max' => $inscripcionMax + $subMax,
            'observacion_inscripcion' => is_array($rootCal) ? ($rootCal['observaciones'] ?? null) : null,
        ];
    }

    /**
     * Solo cuenta hojas calificables (excluye nodos que solo agregan puntaje de hijos).
     *
     * @param  Collection<int, array<string, mixed>>  $calificaciones
     */
    private function accumulateSubProgress(Event $event, $calificaciones, float &$subPts, float &$subMax): void
    {
        foreach ($event->hijos ?? [] as $hijo) {
            if ($hijo->puntaje_desde_hijos) {
                $this->accumulateSubProgress($hijo, $calificaciones, $subPts, $subMax);

                continue;
            }

            if ($hijo->es_calificable && $hijo->puntaje_maximo !== null) {
                $subMax += (float) $hijo->puntaje_maximo;
                $cal = $calificaciones->get((int) $hijo->id);
                if (is_array($cal)) {
                    $subPts += (float) ($cal['puntaje_obtenido'] ?? 0);
                }
            }

            $this->accumulateSubProgress($hijo, $calificaciones, $subPts, $subMax);
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function evidenciaPayload(EventoEvidencia $e): array
    {
        return [
            'id' => $e->id,
            'evento_id' => $e->evento_id,
            'organizacion_id' => $e->organizacion_id,
            'persona_id' => $e->persona_id,
            'inscripcion_id' => $e->inscripcion_id,
            'tipo' => $e->tipo,
            'titulo' => $e->titulo,
            'descripcion' => $e->descripcion,
            'url' => $e->url,
            'file_id' => $e->file_id,
            'file' => $e->relationLoaded('file') && $e->file
                ? [
                    'id' => $e->file->id,
                    'name' => $e->file->name,
                    'path' => $e->file->path,
                    'mime_type' => $e->file->mime_type,
                    'size' => $e->file->size,
                ]
                : null,
            'estado' => $e->estado,
            'created_at' => $e->created_at?->toIso8601String(),
        ];
    }
}
