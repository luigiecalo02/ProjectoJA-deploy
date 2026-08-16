<?php

namespace App\Modules\Events\Http\Controllers;

use App\Modules\Events\Models\Event;
use App\Modules\Events\Services\EventJudgeService;
use App\Modules\Shared\Http\Responses\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final class EventJudgeController
{
    public function __construct(private readonly EventJudgeService $service) {}

    public function show(Request $request, Event $event): JsonResponse
    {
        $subeventoId = $request->query('subevento_id');
        $actividadId = $request->query('actividad_id');
        $payload = $this->service->board(
            $request->user(),
            $event,
            $subeventoId !== null && $subeventoId !== '' ? (int) $subeventoId : null,
            $actividadId !== null && $actividadId !== '' ? (int) $actividadId : null,
        );

        return ApiResponse::success($payload);
    }

    public function evaluaciones(Request $request, Event $event): JsonResponse
    {
        $subeventoId = $request->query('subevento_id');
        $organizacionId = $request->query('organizacion_id');
        $estado = $request->query('estado');
        $distrito = $request->query('distrito');
        $q = $request->query('q');

        $payload = $this->service->evaluaciones(
            $request->user(),
            $event,
            is_string($q) ? $q : null,
            is_string($estado) ? $estado : null,
            is_string($distrito) ? $distrito : null,
            $subeventoId !== null && $subeventoId !== '' ? (int) $subeventoId : null,
            $organizacionId !== null && $organizacionId !== '' ? (int) $organizacionId : null,
        );

        return ApiResponse::success($payload);
    }

    public function storeScore(Request $request, Event $event): JsonResponse
    {
        $data = $request->validate([
            'organizacion_id' => ['required', 'integer', 'exists:organizacion,id'],
            'puntaje_obtenido' => ['nullable', 'numeric', 'min:0'],
            'observaciones' => ['nullable', 'string', 'max:2000'],
            'criterios' => ['nullable', 'array'],
            'criterios.*.criterio_evaluacion_id' => ['required_with:criterios', 'integer', 'exists:criterio_evaluacion,id'],
            'criterios.*.puntos' => ['required_with:criterios', 'numeric', 'min:0'],
        ]);

        $calificacion = $this->service->saveScore($request->user(), $event, $data);

        return ApiResponse::success($calificacion, 'Calificación guardada', Response::HTTP_CREATED);
    }
}
