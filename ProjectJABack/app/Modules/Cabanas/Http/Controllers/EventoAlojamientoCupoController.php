<?php

namespace App\Modules\Cabanas\Http\Controllers;

use App\Modules\Cabanas\Http\Requests\AssignFromCupoRequest;
use App\Modules\Cabanas\Http\Requests\SyncEventoAlojamientoCuposRequest;
use App\Modules\Cabanas\Models\EventoAlojamientoCupo;
use App\Modules\Cabanas\Models\EventoCabanaCama;
use App\Modules\Cabanas\Services\EventoAlojamientoCupoService;
use App\Modules\Events\Models\Event;
use App\Modules\Events\Models\EventoInscripcionPersona;
use App\Modules\Shared\Http\Responses\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class EventoAlojamientoCupoController
{
    public function __construct(private readonly EventoAlojamientoCupoService $cupos) {}

    public function index(Request $request, Event $event): JsonResponse
    {
        abort_unless($this->canManage($request, $event), 403);
        $items = $this->cupos->list($event)->map(fn (EventoAlojamientoCupo $cupo) => $this->cupos->payload($cupo));

        return ApiResponse::success([
            'items' => $items,
            ...$this->cupos->pool($event),
        ]);
    }

    public function sync(SyncEventoAlojamientoCuposRequest $request, Event $event): JsonResponse
    {
        $items = $this->cupos->sync($event, $request->validated('items'), $request->user())
            ->map(fn (EventoAlojamientoCupo $cupo) => $this->cupos->payload($cupo));

        return ApiResponse::success([
            'items' => $items,
            ...$this->cupos->pool($event),
        ], 'Cupos de alojamiento actualizados');
    }

    public function candidates(Request $request, Event $event): JsonResponse
    {
        abort_unless($this->canManage($request, $event) || $this->ownsOpenCupo($request, $event), 403);

        return ApiResponse::success(['items' => $this->cupos->candidates($event)]);
    }

    public function assign(AssignFromCupoRequest $request, Event $event, EventoAlojamientoCupo $cupo): JsonResponse
    {
        $this->assertCupoEvent($event, $cupo);
        abort_unless($this->canUseCupo($request, $cupo), 403);
        $data = $request->validated();
        $asignacion = $this->cupos->assignFromCupo(
            $cupo,
            EventoCabanaCama::query()->findOrFail($data['evento_cabana_cama_id']),
            EventoInscripcionPersona::query()->findOrFail($data['inscripcion_persona_id']),
            $request->user(),
        );

        return ApiResponse::success($asignacion, 'Persona asignada al cupo', 201);
    }

    public function close(Request $request, Event $event, EventoAlojamientoCupo $cupo): JsonResponse
    {
        $this->assertCupoEvent($event, $cupo);
        abort_unless($this->canUseCupo($request, $cupo), 403);
        $closed = $this->cupos->close($cupo, $request->user());

        return ApiResponse::success($this->cupos->payload($closed, true), 'Asignación de cupos cerrada');
    }

    private function canManage(Request $request, Event $event): bool
    {
        $user = $request->user();

        return $user->can('update', $event)
            || $user->hasPermission('cabanas.assign')
            || $user->hasPermission('events.update');
    }

    private function ownsOpenCupo(Request $request, Event $event): bool
    {
        $user = $request->user();

        return $user->hasPermission('cabanas.self_assign')
            && $this->cupos->cupoForUser($event, $user)?->isOpen() === true;
    }

    private function canUseCupo(Request $request, EventoAlojamientoCupo $cupo): bool
    {
        $user = $request->user();

        return $user->hasPermission('cabanas.assign')
            || ((int) $cupo->user_id === (int) $user->id && $user->hasPermission('cabanas.self_assign'));
    }

    private function assertCupoEvent(Event $event, EventoAlojamientoCupo $cupo): void
    {
        abort_unless((int) $cupo->evento_id === (int) $event->id, 404);
    }
}
