<?php

namespace App\Modules\Cabanas\Http\Controllers;

use App\Modules\Cabanas\Http\Requests\SaveCroquisRequest;
use App\Modules\Cabanas\Http\Requests\StoreCabanaRequest;
use App\Modules\Cabanas\Http\Requests\SyncEventoCabanasRequest;
use App\Modules\Cabanas\Http\Requests\UpdateCabanaRequest;
use App\Modules\Cabanas\Models\AsignacionCama;
use App\Modules\Cabanas\Models\Cabana;
use App\Modules\Cabanas\Models\EventoCabana;
use App\Modules\Cabanas\Models\EventoCabanaCama;
use App\Modules\Cabanas\Services\AsignacionCamaService;
use App\Modules\Cabanas\Services\CabanaService;
use App\Modules\Cabanas\Services\ElegibilidadCamaService;
use App\Modules\Cabanas\Services\EventoCabanaService;
use App\Modules\Events\Models\Event;
use App\Modules\Events\Models\EventoInscripcionPersona;
use App\Modules\Shared\Http\Responses\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final class CabanaController
{
    public function __construct(
        private readonly CabanaService $cabanas,
        private readonly EventoCabanaService $eventos,
        private readonly AsignacionCamaService $asignaciones,
        private readonly ElegibilidadCamaService $elegibilidad,
    ) {}

    public function index(Request $request): JsonResponse
    {
        abort_unless($request->user()->can('viewAny', Cabana::class), Response::HTTP_FORBIDDEN);

        return ApiResponse::fromPaginator($this->cabanas->list(
            $request->only('q', 'estado'),
            $request->integer('per_page', 15),
        ));
    }

    public function store(StoreCabanaRequest $request): JsonResponse
    {
        return ApiResponse::success($this->cabanas->create($request->user(), $request->validated()), 'Cabaña creada', 201);
    }

    public function show(Request $request, Cabana $cabana): JsonResponse
    {
        abort_unless($request->user()->can('view', $cabana), Response::HTTP_FORBIDDEN);

        return ApiResponse::success($this->cabanas->find($cabana->id));
    }

    public function update(UpdateCabanaRequest $request, Cabana $cabana): JsonResponse
    {
        return ApiResponse::success($this->cabanas->update($cabana, $request->validated()), 'Cabaña actualizada');
    }

    public function destroy(Request $request, Cabana $cabana): JsonResponse
    {
        abort_unless($request->user()->can('delete', $cabana), Response::HTTP_FORBIDDEN);
        $this->cabanas->delete($cabana);

        return ApiResponse::success(null, 'Cabaña eliminada');
    }

    public function saveCroquis(SaveCroquisRequest $request, Cabana $cabana): JsonResponse
    {
        return ApiResponse::success($this->cabanas->saveCroquis($cabana, $request->validated('pisos')), 'Croquis guardado');
    }

    public function eventIndex(Request $request, Event $event): JsonResponse
    {
        abort_unless($request->user()->can('view', $event) || $request->user()->hasPermission('cabanas.view'), 403);
        $personaId = (int) ($request->user()->persona_id ?? 0);
        $items = $this->eventos->list($event)->map(fn (EventoCabana $cabana) => $this->eventPayload($cabana, $personaId));

        return ApiResponse::success(['items' => $items]);
    }

    public function sync(SyncEventoCabanasRequest $request, Event $event): JsonResponse
    {
        $personaId = (int) ($request->user()->persona_id ?? 0);
        $items = $this->eventos->sync($event, $request->validated('items'))
            ->map(fn (EventoCabana $cabana) => $this->eventPayload($cabana, $personaId));

        return ApiResponse::success(['items' => $items], 'Cabañas del evento sincronizadas');
    }

    public function alojamiento(Request $request, Event $event): JsonResponse
    {
        $canSelfAssign = $request->user()->hasPermission('cabanas.self_assign');
        abort_unless($canSelfAssign || $request->user()->can('view', $event), 403);

        $personaId = (int) ($request->user()->persona_id ?? 0);
        $cabanas = $this->eventos->list($event)
            ->map(fn (EventoCabana $cabana) => $this->eventPayload($cabana, $personaId));
        $asignacion = AsignacionCama::query()
            ->where('evento_id', $event->id)
            ->where('estado', AsignacionCama::ESTADO_ACTIVA)
            ->whereHas('inscripcionPersona', fn ($query) => $query->where('persona_id', $personaId))
            ->with('cama.cuarto.piso.eventoCabana')
            ->first();
        $capacidad = $cabanas->sum('capacidad');
        $ocupacion = $cabanas->sum('ocupacion');
        $elegibilidad = $canSelfAssign
            ? $this->elegibilidad->explain($request->user(), $event)
            : ['eligible' => false, 'codigo' => 'sin_permiso', 'motivo' => 'No tienes permiso para elegir cama.'];

        return ApiResponse::success([
            'evento' => ['id' => $event->id, 'name' => $event->name],
            'cabanas' => $cabanas,
            'asignacion' => $asignacion ? $this->assignmentPayload($asignacion) : null,
            'ocupacion' => $ocupacion,
            'ocupadas' => $ocupacion,
            'capacidad' => $capacidad,
            'puede_seleccionar' => $elegibilidad['eligible'],
            'elegibilidad_codigo' => $elegibilidad['codigo'],
            'elegibilidad_motivo' => $elegibilidad['motivo'],
        ]);
    }

    public function attach(Request $request, Event $event): JsonResponse
    {
        abort_unless($request->user()->hasPermission('cabanas.assign') || $request->user()->hasPermission('cabanas.update'), 403);
        $data = $request->validate(['cabana_id' => ['required', 'integer', 'exists:cabanas,id']]);

        return ApiResponse::success(
            $this->eventos->attach($event, Cabana::query()->findOrFail($data['cabana_id'])),
            'Cabaña asociada al evento',
            201,
        );
    }

    public function detach(Request $request, EventoCabana $eventoCabana): JsonResponse
    {
        abort_unless($request->user()->hasPermission('cabanas.assign') || $request->user()->hasPermission('cabanas.update'), 403);
        $this->eventos->detach($eventoCabana);

        return ApiResponse::success(null, 'Cabaña retirada del evento');
    }

    public function assign(Request $request, EventoCabanaCama $cama): JsonResponse
    {
        abort_unless($request->user()->hasPermission('cabanas.assign'), 403);
        $data = $request->validate(['inscripcion_persona_id' => ['required', 'integer', 'exists:evento_inscripcion_persona,id']]);
        $asignacion = $this->asignaciones->assignFor(
            $cama,
            EventoInscripcionPersona::query()->findOrFail($data['inscripcion_persona_id']),
            $request->user(),
        );

        return ApiResponse::success($asignacion, 'Cama asignada', 201);
    }

    public function selfAssign(Request $request, EventoCabanaCama $cama): JsonResponse
    {
        abort_unless($request->user()->hasPermission('cabanas.self_assign'), 403);

        return ApiResponse::success($this->asignaciones->selfAssign($cama, $request->user()), 'Cama elegida', 201);
    }

    public function release(Request $request, AsignacionCama $asignacion): JsonResponse
    {
        $isSelf = (int) $asignacion->inscripcionPersona()->value('persona_id') === (int) $request->user()->persona_id;
        abort_unless($request->user()->hasPermission('cabanas.assign') || ($isSelf && $request->user()->hasPermission('cabanas.self_assign')), 403);

        return ApiResponse::success($this->asignaciones->release($asignacion), 'Asignación liberada');
    }

    private function eventPayload(EventoCabana $cabana, int $personaId): array
    {
        $ocupacion = 0;
        $capacidad = 0;

        $pisos = $cabana->pisos->map(function ($piso) use ($personaId, &$ocupacion, &$capacidad) {
            return [
                'id' => $piso->id,
                'nombre' => $piso->nombre,
                'ancho' => $piso->ancho,
                'alto' => $piso->alto,
                'orden' => $piso->orden,
                'cuartos' => $piso->cuartos->map(function ($cuarto) use ($personaId, &$ocupacion, &$capacidad) {
                    $roomOccupancy = 0;
                    $beds = $cuarto->camas->map(function ($cama) use ($personaId, &$ocupacion, &$capacidad, &$roomOccupancy) {
                        $activas = $cama->asignaciones->where('estado', AsignacionCama::ESTADO_ACTIVA);
                        $bedOccupancy = $activas->count();
                        $ocupacion += $bedOccupancy;
                        $roomOccupancy += $bedOccupancy;
                        $capacidad += (int) $cama->capacidad;

                        return [
                            'id' => $cama->id,
                            'codigo' => $cama->codigo,
                            'nombre' => $cama->nombre,
                            'capacidad' => $cama->capacidad,
                            'x' => $cama->x,
                            'y' => $cama->y,
                            'ancho' => $cama->ancho,
                            'alto' => $cama->alto,
                            'rotacion' => $cama->rotacion,
                            'estado' => $cama->estado,
                            'ocupacion' => $bedOccupancy,
                            'ocupadas' => $bedOccupancy,
                            'asignada_a_mi' => $activas->contains(
                                fn ($a) => (int) $a->inscripcionPersona?->persona_id === $personaId
                            ),
                        ];
                    })->values();

                    return [
                        'id' => $cuarto->id,
                        'nombre' => $cuarto->nombre,
                        'codigo' => $cuarto->codigo,
                        'x' => $cuarto->x,
                        'y' => $cuarto->y,
                        'ancho' => $cuarto->ancho,
                        'alto' => $cuarto->alto,
                        'genero' => $cuarto->genero,
                        'capacidad' => $cuarto->capacidad,
                        'ocupacion' => $roomOccupancy,
                        'ocupadas' => $roomOccupancy,
                        'orden' => $cuarto->orden,
                        'camas' => $beds,
                    ];
                })->values(),
            ];
        })->values();

        return [
            'id' => $cabana->id,
            'evento_id' => $cabana->evento_id,
            'cabana_id' => $cabana->cabana_id,
            'orden' => $cabana->orden,
            'nombre' => $cabana->nombre,
            'descripcion' => $cabana->descripcion,
            'ancho' => $cabana->ancho,
            'alto' => $cabana->alto,
            'estado' => $cabana->estado,
            'pisos' => $pisos,
            'ocupacion' => $ocupacion,
            'ocupadas' => $ocupacion,
            'capacidad' => $capacidad,
            'capacidad_total' => $capacidad,
        ];
    }

    private function assignmentPayload(AsignacionCama $asignacion): array
    {
        $cama = $asignacion->cama;
        $cuarto = $cama?->cuarto;
        $piso = $cuarto?->piso;
        $cabana = $piso?->eventoCabana;

        return [
            'id' => $asignacion->id,
            'evento_cabana_cama_id' => $asignacion->evento_cabana_cama_id,
            'cama' => $cama ? ['id' => $cama->id, 'codigo' => $cama->codigo, 'nombre' => $cama->nombre] : null,
            'cuarto' => $cuarto ? ['id' => $cuarto->id, 'nombre' => $cuarto->nombre, 'genero' => $cuarto->genero] : null,
            'piso' => $piso ? ['id' => $piso->id, 'nombre' => $piso->nombre] : null,
            'cabana' => $cabana ? ['id' => $cabana->id, 'nombre' => $cabana->nombre] : null,
        ];
    }
}
