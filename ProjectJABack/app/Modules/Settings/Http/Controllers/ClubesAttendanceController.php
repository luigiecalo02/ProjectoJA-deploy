<?php

namespace App\Modules\Settings\Http\Controllers;

use App\Modules\Events\Models\Event;
use App\Modules\Settings\Services\ClubesAttendanceService;
use App\Modules\Shared\Http\Responses\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class ClubesAttendanceController
{
    public function __construct(private readonly ClubesAttendanceService $attendance) {}

    public function events(Request $request): JsonResponse
    {
        $this->assertClubesClient($request);

        return ApiResponse::success($this->attendance->listEvents($request->user()));
    }

    public function ranking(Request $request): JsonResponse
    {
        $this->assertClubesClient($request);

        return ApiResponse::success($this->attendance->ranking($request->user()));
    }

    public function show(Request $request, Event $event): JsonResponse
    {
        $this->assertClubesClient($request);

        return ApiResponse::success($this->attendance->show($request->user(), $event));
    }

    public function sync(Request $request, Event $event): JsonResponse
    {
        $this->assertClubesClient($request);

        $data = $request->validate([
            'persona_ids' => ['present', 'array'],
            'persona_ids.*' => ['integer', 'exists:personas,id'],
            'justificados' => ['sometimes', 'array'],
            'justificados.*' => ['integer', 'exists:personas,id'],
        ]);

        return ApiResponse::success(
            $this->attendance->sync(
                $request->user(),
                $event,
                $data['persona_ids'] ?? [],
                $data['justificados'] ?? [],
            ),
            'Asistencia guardada',
        );
    }

    private function assertClubesClient(Request $request): void
    {
        abort_unless(
            $request->header('X-Clubes-Client') === 'clubes',
            403,
            'La asistencia solo se gestiona desde el front de Clubes.',
        );
    }
}
