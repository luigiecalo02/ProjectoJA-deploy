<?php

namespace App\Modules\Settings\Http\Controllers;

use App\Modules\Events\Models\EventoParticipacionAbono;
use App\Modules\Settings\Services\ClubesAbonosService;
use App\Modules\Shared\Http\Responses\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;

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
            'comprobante' => ['nullable', 'file', 'image', 'mimes:jpeg,jpg,png,webp', 'max:8192'],
        ]);

        $comprobante = $request->file('comprobante');

        return ApiResponse::success(
            $this->abonos->store(
                $request->user(),
                (int) $data['evento_id'],
                (int) $data['persona_id'],
                (float) $data['monto'],
                $data['nota'] ?? null,
                $data['modo'] ?? 'integrante',
                $comprobante instanceof UploadedFile ? $comprobante : null,
            ),
            'Abono registrado',
        );
    }

    public function storeComprobante(Request $request): JsonResponse
    {
        $this->assertClubesClient($request);
        $data = $request->validate([
            'evento_id' => ['required', 'integer', 'exists:events,id'],
            'comprobante' => ['required', 'file', 'image', 'mimes:jpeg,jpg,png,webp', 'max:8192'],
        ]);

        $comprobante = $request->file('comprobante');
        abort_unless($comprobante instanceof UploadedFile, 422, 'Adjunta el pantallazo del abono.');

        return ApiResponse::success(
            $this->abonos->submitComprobante(
                $request->user(),
                (int) $data['evento_id'],
                $comprobante,
            ),
            'Abono pendiente de aprobación',
        );
    }

    public function review(Request $request, EventoParticipacionAbono $abono): JsonResponse
    {
        $this->assertClubesClient($request);
        $data = $request->validate([
            'estado' => ['required', 'in:aprobado,rechazado'],
            'monto' => ['exclude_unless:estado,aprobado', 'required', 'numeric', 'min:0.01', 'max:99999999.99'],
        ]);

        return ApiResponse::success(
            $this->abonos->review(
                $request->user(),
                $abono,
                $data['estado'],
                isset($data['monto']) ? (float) $data['monto'] : null,
            ),
            $data['estado'] === 'aprobado' ? 'Abono aprobado' : 'Abono rechazado',
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
