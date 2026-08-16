<?php

namespace App\Modules\Events\Http\Controllers;

use App\Modules\Events\Services\SeguroConsultaService;
use App\Modules\Shared\Http\Responses\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final class SeguroConsultaController
{
    public function __construct(
        private readonly SeguroConsultaService $consultaService,
    ) {}

    public function index(Request $request): JsonResponse
    {
        abort_unless(
            $request->user()?->hasPermission('events.view'),
            Response::HTTP_FORBIDDEN,
        );

        $validated = $request->validate([
            'q' => ['required', 'string', 'min:2', 'max:120'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:50'],
        ]);

        return ApiResponse::fromPaginator($this->consultaService->search(
            $request->user(),
            $validated['q'],
            (int) ($validated['per_page'] ?? 9),
        ));
    }
}
