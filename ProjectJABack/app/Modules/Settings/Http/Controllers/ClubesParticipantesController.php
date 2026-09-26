<?php

namespace App\Modules\Settings\Http\Controllers;

use App\Modules\Events\Models\Event;
use App\Modules\Settings\Services\ClubesParticipantesService;
use App\Modules\Shared\Http\Responses\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class ClubesParticipantesController
{
    public function __construct(private readonly ClubesParticipantesService $participantes) {}

    public function show(Request $request, Event $event): JsonResponse
    {
        $this->assertClubesClient($request);

        return ApiResponse::success($this->participantes->show($request->user(), $event));
    }

    public function me(Request $request, Event $event): JsonResponse
    {
        $this->assertClubesClient($request);

        return ApiResponse::success($this->participantes->self($request->user(), $event));
    }

    public function join(Request $request, Event $event): JsonResponse
    {
        $this->assertClubesClient($request);
        $data = $request->validate([
            'participa' => ['required', 'boolean'],
            'ventas' => ['nullable', 'array'],
            'ventas.*.producto_servicio_id' => ['required', 'integer', 'exists:productos_servicios,id'],
            'ventas.*.cantidad' => ['required', 'integer', 'min:0', 'max:99999'],
        ]);

        return ApiResponse::success(
            $this->participantes->join(
                $request->user(),
                $event,
                (bool) $data['participa'],
                $data['ventas'] ?? [],
            ),
            $data['participa'] ? 'Participación guardada' : 'Ya no participas',
        );
    }

    public function sync(Request $request, Event $event): JsonResponse
    {
        $this->assertClubesClient($request);
        $data = $request->validate([
            'persona_ids' => ['nullable', 'array'],
            'persona_ids.*' => ['integer', 'exists:personas,id'],
            'participantes' => ['nullable', 'array'],
            'participantes.*.persona_id' => ['required_with:participantes', 'integer', 'exists:personas,id'],
            'participantes.*.participa' => ['nullable', 'boolean'],
            'participantes.*.ventas' => ['nullable', 'array'],
            'participantes.*.ventas.*.producto_servicio_id' => ['required', 'integer', 'exists:productos_servicios,id'],
            'participantes.*.ventas.*.cantidad' => ['required', 'integer', 'min:0', 'max:99999'],
        ]);

        return ApiResponse::success(
            $this->participantes->sync(
                $request->user(),
                $event,
                $data['persona_ids'] ?? [],
                $data['participantes'] ?? [],
            ),
            'Participantes guardados',
        );
    }

    private function assertClubesClient(Request $request): void
    {
        abort_unless(
            $request->header('X-Clubes-Client') === 'clubes',
            403,
            'Los participantes solo se gestionan desde el front de Clubes.',
        );
    }
}
