<?php

namespace App\Modules\Settings\Http\Controllers;

use App\Modules\Settings\Services\ClubesAbonosService;
use App\Modules\Shared\Http\Responses\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class ClubesAbonosController
{
    public function __construct(private readonly ClubesAbonosService $abonos) {}

    public function board(Request $request): JsonResponse
    {
        $this->assertClubesClient($request);
        $data = $request->validate([
            'modo' => ['nullable', 'in:integrante,actividad'],
            'persona_id' => ['nullable', 'integer', 'exists:personas,id'],
            'evento_id' => ['nullable', 'integer', 'exists:events,id'],
        ]);

        return ApiResponse::success($this->abonos->board(
            $request->user(),
            $data['modo'] ?? 'integrante',
            isset($data['persona_id']) ? (int) $data['persona_id'] : null,
            isset($data['evento_id']) ? (int) $data['evento_id'] : null,
        ));
    }

    public function store(Request $request): JsonResponse
    {
        $this->assertClubesClient($request);
        $data = $request->validate([
            'evento_id' => ['required', 'integer', 'exists:events,id'],
            'persona_id' => ['required', 'integer', 'exists:personas,id'],
            'monto' => ['required', 'numeric', 'min:0.01', 'max:99999999.99'],
            'nota' => ['nullable', 'string', 'max:255'],
            'modo' => ['nullable', 'in:integrante,actividad'],
        ]);

        return ApiResponse::success(
            $this->abonos->store(
                $request->user(),
                (int) $data['evento_id'],
                (int) $data['persona_id'],
                (float) $data['monto'],
                $data['nota'] ?? null,
                $data['modo'] ?? 'integrante',
            ),
            'Abono registrado',
        );
    }

    private function assertClubesClient(Request $request): void
    {
        abort_unless(
            $request->header('X-Clubes-Client') === 'clubes',
            403,
            'Los abonos solo se gestionan desde el front de Clubes.',
        );
    }
}
