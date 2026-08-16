<?php

namespace App\Modules\Events\Http\Controllers;

use App\Modules\Events\Models\Event;
use App\Modules\Events\Services\EventStandingsService;
use App\Modules\Shared\Http\Responses\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class EventStandingsController
{
    public function __construct(private readonly EventStandingsService $service) {}

    public function show(Request $request, Event $event): JsonResponse
    {
        $subeventoId = $request->query('subevento_id');
        $sort = (string) $request->query('sort', 'puesto');
        $q = $request->query('q');

        $payload = $this->service->standings(
            $request->user(),
            $event,
            $subeventoId !== null && $subeventoId !== '' ? (int) $subeventoId : null,
            $sort,
            is_string($q) ? $q : null,
        );

        return ApiResponse::success($payload);
    }

    public function tree(Request $request, Event $event): JsonResponse
    {
        $sort = (string) $request->query('sort', 'puesto');
        $q = $request->query('q');

        $payload = $this->service->standingsTree(
            $request->user(),
            $event,
            $sort,
            is_string($q) ? $q : null,
        );

        return ApiResponse::success($payload);
    }
}
