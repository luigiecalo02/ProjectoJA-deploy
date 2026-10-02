<?php

namespace App\Modules\Settings\Http\Controllers;

use App\Modules\Events\Models\Event;
use App\Modules\Settings\Services\ClubesInscripcionesService;
use App\Modules\Shared\Http\Responses\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class ClubesInscripcionesController
{
    public function __construct(private readonly ClubesInscripcionesService $inscripciones) {}

    public function show(Request $request, Event $evento): JsonResponse
    {
        $this->assertClubesClient($request);

        return ApiResponse::success($this->inscripciones->show($request->user(), $evento));
    }

    public function sync(Request $request, Event $evento): JsonResponse
    {
        $this->assertClubesClient($request);
        $data = $request->validate([
            'persona_ids' => ['present', 'array'],
            'persona_ids.*' => ['integer', 'exists:personas,id'],
        ]);

        return ApiResponse::success(
            $this->inscripciones->sync($request->user(), $evento, $data['persona_ids'] ?? []),
            'Inscripciones guardadas',
        );
    }

    public function me(Request $request, Event $evento): JsonResponse
    {
        $this->assertClubesClient($request);

        return ApiResponse::success($this->inscripciones->me($request->user(), $evento));
    }

    public function join(Request $request, Event $evento): JsonResponse
    {
        $this->assertClubesClient($request);
        $data = $request->validate([
            'inscrito' => ['required', 'boolean'],
        ]);

        return ApiResponse::success(
            $this->inscripciones->join($request->user(), $evento, (bool) $data['inscrito']),
            $data['inscrito'] ? 'Quedaste inscrito' : 'Ya no estás inscrito',
        );
    }

    private function assertClubesClient(Request $request): void
    {
        abort_unless(
            $request->header('X-Clubes-Client') === 'clubes',
            403,
            'Las inscripciones del club solo se gestionan desde Clubes.',
        );
    }
}
