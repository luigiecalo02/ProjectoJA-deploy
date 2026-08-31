<?php

namespace App\Modules\Events\Http\Controllers;

use App\Modules\Events\Http\Requests\StorePublicEventoInscripcionRequest;
use App\Modules\Events\Models\Event;
use App\Modules\Events\Services\PublicEventoService;
use App\Modules\Shared\Http\Responses\ApiResponse;
use Illuminate\Http\JsonResponse;
use Symfony\Component\HttpFoundation\Response;

final class PublicEventoController
{
    public function __construct(
        private readonly PublicEventoService $publicEventos,
    ) {}

    public function index(): JsonResponse
    {
        $items = $this->publicEventos->list()
            ->map(fn (Event $event) => $this->publicEventos->cardPayload($event))
            ->values()
            ->all();

        return ApiResponse::success($items);
    }

    public function show(Event $event): JsonResponse
    {
        $eligible = $this->publicEventos->findEligibleOrFail((int) $event->id);

        return ApiResponse::success($this->publicEventos->detail($eligible));
    }

    public function store(StorePublicEventoInscripcionRequest $request, Event $event): JsonResponse
    {
        $result = $this->publicEventos->enroll(
            $event,
            $request->validated(),
            $request->file('comprobante'),
        );

        $mensaje = $result['usuario_creado']
            ? 'Inscripción enviada. Revisa tu correo para confirmar la cuenta y hacer seguimiento.'
            : 'Inscripción enviada. El seguimiento llegará al correo que registraste.';

        return ApiResponse::success($result, $mensaje, Response::HTTP_CREATED);
    }
}
