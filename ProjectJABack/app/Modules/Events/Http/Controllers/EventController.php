<?php

namespace App\Modules\Events\Http\Controllers;

use App\Models\User;
use App\Modules\Events\Http\Requests\StoreEventRequest;
use App\Modules\Events\Http\Requests\UpdateEventRequest;
use App\Modules\Events\Http\Requests\UploadEventImageRequest;
use App\Modules\Events\Models\Event;
use App\Modules\Events\Services\EventListEnricher;
use App\Modules\Events\Services\EventParticipationService;
use App\Modules\Events\Services\EventService;
use App\Modules\Shared\Http\Responses\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Symfony\Component\HttpFoundation\Response;

final class EventController
{
    public function __construct(
        private readonly EventService $eventService,
        private readonly EventParticipationService $participationService,
        private readonly EventListEnricher $listEnricher,
    ) {}

    public function index(Request $request): JsonResponse
    {
        abort_unless($request->user()->can('viewAny', Event::class), Response::HTTP_FORBIDDEN);

        $paginator = $this->eventService->list(
            $request->user(),
            $request->only(['q', 'is_active', 'estado', 'evento_padre_id', 'solo_raiz', 'tipo_evento_id', 'incluir_hijos']),
            (int) $request->integer('per_page', 15),
        );

        $enrolledMap = $this->participationService->enrolledMap(
            $request->user(),
            collect($paginator->items())->pluck('id')->map(fn ($id) => (int) $id)->all(),
        );

        $paginator->getCollection()->transform(function (Event $event) use ($enrolledMap, $request) {
            $payload = $this->payload($event, $request->user());
            $payload['inscrito'] = (bool) ($enrolledMap[$event->id] ?? false);

            return $this->listEnricher->enrich($request->user(), $event, $payload);
        });

        return ApiResponse::fromPaginator($paginator);
    }

    public function store(StoreEventRequest $request): JsonResponse
    {
        $event = $this->eventService->create($request->user(), $request->validated());

        return ApiResponse::success($this->payload($event), 'Evento creado', Response::HTTP_CREATED);
    }

    public function show(Request $request, Event $event): JsonResponse
    {
        abort_unless($request->user()->can('view', $event), Response::HTTP_FORBIDDEN);

        return ApiResponse::success($this->payload(
            $this->eventService->find($event->id),
            $request->user()
        ));
    }

    public function update(UpdateEventRequest $request, Event $event): JsonResponse
    {
        $event = $this->eventService->update($event, $request->user(), $request->validated());

        return ApiResponse::success($this->payload($event), 'Evento actualizado');
    }

    public function destroy(Request $request, Event $event): JsonResponse
    {
        abort_unless($request->user()->can('delete', $event), Response::HTTP_FORBIDDEN);
        $this->eventService->delete($event);

        return ApiResponse::success(null, 'Evento eliminado');
    }

    public function duplicate(Request $request, Event $event): JsonResponse
    {
        abort_unless($request->user()->can('view', $event), Response::HTTP_FORBIDDEN);
        abort_unless($request->user()->can('create', Event::class), Response::HTTP_FORBIDDEN);

        $name = $request->input('name');
        $cloned = $this->eventService->duplicate(
            $event,
            $request->user(),
            ['name' => is_string($name) ? $name : null],
        );

        return ApiResponse::success($this->payload($cloned), 'Evento duplicado', Response::HTTP_CREATED);
    }

    public function image(UploadEventImageRequest $request, Event $event): JsonResponse
    {
        $event = $this->eventService->storeImage($event, $request->file('image'), $request->user());

        return ApiResponse::success($this->payload($event), 'Imagen actualizada');
    }

    public function tipos(Request $request): JsonResponse
    {
        abort_unless($request->user()->can('viewAny', Event::class), Response::HTTP_FORBIDDEN);

        $tipos = collect($this->eventService->listTiposEvento())->map(fn ($tipo) => [
            'id' => $tipo->id,
            'nombre' => $tipo->nombre,
            'slug' => $tipo->slug,
            'descripcion' => $tipo->descripcion,
            'color' => $tipo->color,
            'icono' => $tipo->icono,
            'orden' => $tipo->orden,
        ])->values()->all();

        return ApiResponse::success($tipos);
    }

    public function jueces(Request $request): JsonResponse
    {
        $user = $request->user();
        abort_unless(
            $user->can('viewAny', Event::class)
            || $user->hasPermission('events.create')
            || $user->hasPermission('events.update'),
            Response::HTTP_FORBIDDEN,
        );

        $jueces = collect($this->eventService->listJueces())->map(fn ($juez) => [
            'id' => $juez->id,
            'name' => $juez->name,
            'email' => $juez->email,
        ])->values()->all();

        return ApiResponse::success($jueces);
    }

    public function supervisores(Request $request): JsonResponse
    {
        $user = $request->user();
        abort_unless(
            $user->can('viewAny', Event::class)
            || $user->hasPermission('events.create')
            || $user->hasPermission('events.update'),
            Response::HTTP_FORBIDDEN,
        );

        $supervisores = collect($this->eventService->listSupervisores())->map(fn ($supervisor) => [
            'id' => $supervisor->id,
            'name' => $supervisor->name,
            'email' => $supervisor->email,
        ])->values()->all();

        return ApiResponse::success($supervisores);
    }

    public function reorderChildren(Request $request, Event $event): JsonResponse
    {
        abort_unless($request->user()->can('update', $event), Response::HTTP_FORBIDDEN);

        $validated = $request->validate([
            'ordered_ids' => ['required', 'array', 'min:1'],
            'ordered_ids.*' => ['integer', 'exists:events,id'],
        ]);

        $this->eventService->reorderChildren($event, $validated['ordered_ids'], $request->user());

        return ApiResponse::success(null, 'Orden de subeventos actualizado');
    }

    public function move(Request $request, Event $event): JsonResponse
    {
        abort_unless($request->user()->can('update', $event), Response::HTTP_FORBIDDEN);

        $validated = $request->validate([
            'evento_padre_id' => ['required', 'integer', 'exists:events,id'],
            'before_id' => ['nullable', 'integer', 'exists:events,id'],
        ]);

        $moved = $this->eventService->move(
            $event,
            $request->user(),
            (int) $validated['evento_padre_id'],
            array_key_exists('before_id', $validated) && $validated['before_id'] !== null
                ? (int) $validated['before_id']
                : null,
        );

        return ApiResponse::success($this->payload($this->eventService->find($moved->id)), 'Subevento movido');
    }

    /**
     * @param  Collection<int, User>|null  $inheritedJueces
     * @param  Collection<int, User>|null  $inheritedSupervisores
     */
    private function payload(
        Event $event,
        ?User $actor = null,
        $inheritedJueces = null,
        $inheritedSupervisores = null
    ): array {
        $organizaciones = $event->relationLoaded('organizaciones')
            ? $event->organizaciones
            : $event->organizaciones()->get(['organizacion.id', 'organizacion.nombre', 'organizacion.codigo']);

        $tipos = $event->relationLoaded('tiposOrganizacion')
            ? $event->tiposOrganizacion
            : $event->tiposOrganizacion()->get(['tipo_organizacion.id', 'tipo_organizacion.nombre']);

        $tipoEvento = $event->relationLoaded('tipoEvento')
            ? $event->tipoEvento
            : $event->tipoEvento()->first();

        $categoria = $event->relationLoaded('categoriaSubevento')
            ? $event->categoriaSubevento
            : $event->categoriaSubevento()->first();

        $ownJueces = $event->ownJueces();
        $ownSupervisores = $event->ownSupervisores();
        [$juecesEfectivos, $juecesHeredados] = $event->resolveEffectiveJueces($inheritedJueces);
        [$supervisoresEfectivos, $supervisoresHeredados] = $event->resolveEffectiveSupervisores($inheritedSupervisores);
        $mapUser = static fn ($u) => [
            'id' => (int) $u->id,
            'name' => $u->name,
            'email' => $u->email,
        ];
        $passJueces = $ownJueces->isNotEmpty() ? $ownJueces : $juecesEfectivos;
        $passSupervisores = $ownSupervisores->isNotEmpty() ? $ownSupervisores : $supervisoresEfectivos;
        $visibleChildren = $event->relationLoaded('hijos')
            ? $event->hijos->filter(
                fn (Event $child) => $actor === null || $child->isVisibleTo($actor)
            )
            : null;

        return [
            'id' => $event->id,
            'evento_padre_id' => $event->evento_padre_id,
            'orden' => (int) ($event->orden ?? 0),
            'organizacion_id' => $event->organizacion_id,
            'tipo_evento_id' => $event->tipo_evento_id,
            'tipo_evento' => $tipoEvento
                ? [
                    'id' => $tipoEvento->id,
                    'nombre' => $tipoEvento->nombre,
                    'slug' => $tipoEvento->slug,
                    'color' => $tipoEvento->color,
                    'icono' => $tipoEvento->icono,
                ]
                : null,
            'categoria_subevento_id' => $event->categoria_subevento_id,
            'categoria_subevento' => $categoria
                ? [
                    'id' => $categoria->id,
                    'nombre' => $categoria->nombre,
                    'slug' => $categoria->slug,
                    'color' => $categoria->color,
                    'icono' => $categoria->icono,
                    'maneja_puntos' => (bool) $categoria->maneja_puntos,
                    'maneja_fecha_inicio' => (bool) $categoria->maneja_fecha_inicio,
                    'maneja_fecha_fin' => (bool) $categoria->maneja_fecha_fin,
                ]
                : null,
            'organizacion' => $event->relationLoaded('organizacion') && $event->organizacion
                ? [
                    'id' => $event->organizacion->id,
                    'nombre' => $event->organizacion->nombre,
                    'codigo' => $event->organizacion->codigo,
                ]
                : null,
            'name' => $event->name,
            'descripcion' => $event->descripcion,
            'reglas' => $event->reglas,
            'lugar' => $event->lugar,
            'latitud' => $event->latitud !== null ? (float) $event->latitud : null,
            'longitud' => $event->longitud !== null ? (float) $event->longitud : null,
            'image_url' => $event->image_url,
            'starts_at' => $event->starts_at?->toIso8601String(),
            'ends_at' => $event->ends_at?->toIso8601String(),
            'is_active' => (bool) $event->is_active,
            'estado' => $event->estado ?? Event::ESTADO_BORRADOR,
            'visibilidad' => $event->visibilidad ?? Event::VISIBILIDAD_ORGANIZACION,
            'es_en_sitio' => (bool) $event->es_en_sitio,
            'es_calificable' => (bool) $event->es_calificable,
            'puntaje_maximo' => $event->puntaje_maximo !== null ? (float) $event->puntaje_maximo : null,
            'puntaje_desde_hijos' => (bool) $event->puntaje_desde_hijos,
            'tiempo_estimado_minutos' => $event->tiempo_estimado_minutos,
            'participantes_min' => $event->participantes_min,
            'participantes_max' => $event->participantes_max,
            'equipos_org_min' => $event->equipos_org_min,
            'equipos_org_max' => $event->equipos_org_max,
            'es_conjunto' => (bool) $event->es_conjunto,
            'nivel_conjunto' => $event->nivel_conjunto,
            'maneja_fecha_fin' => (bool) $event->maneja_fecha_fin,
            'maneja_penalizaciones' => (bool) $event->maneja_penalizaciones,
            'puntos_penalizacion' => $event->puntos_penalizacion !== null ? (float) $event->puntos_penalizacion : null,
            'reglas_penalizacion' => $event->reglas_penalizacion,
            'requiere_evidencia' => (bool) $event->requiere_evidencia,
            'tipos_evidencia' => array_values($event->tipos_evidencia ?? []),
            'requiere_pago' => (bool) $event->requiere_pago,
            'precio' => $event->precio !== null ? (float) $event->precio : null,
            'precio_fuera_tiempo' => $event->precio_fuera_tiempo !== null ? (float) $event->precio_fuera_tiempo : null,
            'precio_acompanante' => $event->precio_acompanante !== null ? (float) $event->precio_acompanante : null,
            'precio_acompanante_fuera_tiempo' => $event->precio_acompanante_fuera_tiempo !== null ? (float) $event->precio_acompanante_fuera_tiempo : null,
            'precio_acompanante_menor' => $event->precio_acompanante_menor !== null ? (float) $event->precio_acompanante_menor : null,
            'precio_acompanante_menor_fuera_tiempo' => $event->precio_acompanante_menor_fuera_tiempo !== null ? (float) $event->precio_acompanante_menor_fuera_tiempo : null,
            'precio_directiva' => $event->precio_directiva !== null ? (float) $event->precio_directiva : null,
            'precio_directiva_fuera_tiempo' => $event->precio_directiva_fuera_tiempo !== null ? (float) $event->precio_directiva_fuera_tiempo : null,
            'descuentos_directiva' => array_values(array_map(static function ($row) {
                return [
                    'codigo' => (string) ($row['codigo'] ?? ''),
                    'nombre' => (string) ($row['nombre'] ?? ''),
                    'porcentaje' => (float) ($row['porcentaje'] ?? 0),
                ];
            }, is_array($event->descuentos_directiva) ? $event->descuentos_directiva : [])),
            'fecha_limite_pago' => $event->fecha_limite_pago?->toIso8601String(),
            'metodo_pago' => $event->metodo_pago,
            'requiere_seguro' => (bool) $event->requiere_seguro,
            'tipo_seguro_id' => $event->tipo_seguro_id,
            'seguro_valor' => $event->seguro_valor !== null ? (float) $event->seguro_valor : null,
            'seguro_fecha_inicio' => $event->seguro_fecha_inicio?->toDateString(),
            'seguro_fecha_fin' => $event->seguro_fecha_fin?->toDateString(),
            'cupo_minimo' => $event->cupo_minimo,
            'cupo_maximo' => $event->cupo_maximo,
            'cupo_ilimitado' => (bool) $event->cupo_ilimitado,
            'cupo_max_organizacion' => $event->cupo_max_organizacion,
            'cupo_max_club' => $event->cupo_max_club,
            'cupo_max_iglesia' => $event->cupo_max_iglesia,
            'permite_inscripcion_individual' => (bool) $event->permite_inscripcion_individual,
            'permite_inscripcion_organizacion' => (bool) $event->permite_inscripcion_organizacion,
            'permite_inscripcion_club' => (bool) $event->permite_inscripcion_club,
            'permite_inscripcion_iglesia' => (bool) $event->permite_inscripcion_iglesia,
            'fecha_limite_inscripcion' => $event->fecha_limite_inscripcion?->toIso8601String(),
            'puntos_inscripcion_a_tiempo' => $event->puntos_inscripcion_a_tiempo !== null
                ? (float) $event->puntos_inscripcion_a_tiempo
                : null,
            'puntos_inscripcion_fuera_tiempo' => $event->puntos_inscripcion_fuera_tiempo !== null
                ? (float) $event->puntos_inscripcion_fuera_tiempo
                : null,
            'criterios' => $event->relationLoaded('criterios')
                ? $event->criterios->map(fn ($c) => [
                    'id' => $c->id,
                    'nombre' => $c->nombre,
                    'descripcion' => $c->descripcion,
                    'puntos' => (float) $c->pivot->puntos,
                    'orden' => (int) $c->pivot->orden,
                ])->values()->all()
                : [],
            'created_by' => $event->created_by,
            'juez_ids' => $ownJueces->pluck('id')->map(fn ($id) => (int) $id)->values()->all(),
            'jueces' => $ownJueces->map($mapUser)->values()->all(),
            'jueces_efectivos' => $juecesEfectivos->map($mapUser)->values()->all(),
            'jueces_heredados' => $juecesHeredados,
            'supervisor_ids' => $ownSupervisores->pluck('id')->map(fn ($id) => (int) $id)->values()->all(),
            'supervisores' => $ownSupervisores->map($mapUser)->values()->all(),
            'supervisores_efectivos' => $supervisoresEfectivos->map($mapUser)->values()->all(),
            'supervisores_heredados' => $supervisoresHeredados,
            'organizacion_ids' => $organizaciones->pluck('id')->map(fn ($id) => (int) $id)->values()->all(),
            'organizaciones' => $organizaciones->map(fn ($org) => [
                'id' => $org->id,
                'nombre' => $org->nombre,
                'codigo' => $org->codigo,
            ])->values()->all(),
            'tipo_organizacion_ids' => $tipos->pluck('id')->map(fn ($id) => (int) $id)->values()->all(),
            'tipos_organizacion' => $tipos->map(fn ($tipo) => [
                'id' => $tipo->id,
                'nombre' => $tipo->nombre,
            ])->values()->all(),
            'padre' => $event->relationLoaded('padre') && $event->padre
                ? [
                    'id' => $event->padre->id,
                    'name' => $event->padre->name,
                ]
                : null,
            'hijos_count' => $visibleChildren !== null
                ? $visibleChildren->count()
                : (int) ($actor === null ? ($event->hijos_count ?? 0) : 0),
            'hijos' => $visibleChildren !== null
                ? $visibleChildren->map(
                    fn (Event $hijo) => $this->payload(
                        $hijo,
                        $actor,
                        $passJueces,
                        $passSupervisores
                    )
                )->values()->all()
                : null,
            'created_at' => $event->created_at,
            'updated_at' => $event->updated_at,
        ];
    }
}
