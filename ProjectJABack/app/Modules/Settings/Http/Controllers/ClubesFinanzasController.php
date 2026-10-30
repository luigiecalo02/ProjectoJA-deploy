<?php

namespace App\Modules\Settings\Http\Controllers;

use App\Modules\Settings\Models\ClubesCajaMovimiento;
use App\Modules\Settings\Services\ClubesFinanzasService;
use App\Modules\Shared\Http\Responses\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

final class ClubesFinanzasController
{
    public function __construct(private readonly ClubesFinanzasService $finanzas) {}

    public function resumen(Request $request): JsonResponse
    {
        $this->assertClubesClient($request);
        $data = $request->validate([
            'desde' => ['nullable', 'date'],
            'hasta' => ['nullable', 'date', 'after_or_equal:desde'],
            'cuenta_bancaria_id' => ['nullable', 'integer', 'exists:cuentas_bancarias,id'],
        ]);

        return ApiResponse::success($this->finanzas->resumen(
            $request->user(),
            $data['desde'] ?? null,
            $data['hasta'] ?? null,
            isset($data['cuenta_bancaria_id']) ? (int) $data['cuenta_bancaria_id'] : null,
        ));
    }

    public function storeMovimiento(Request $request): JsonResponse
    {
        $this->assertClubesClient($request);
        $data = $request->validate([
            'cuenta_bancaria_id' => ['required', 'integer', 'exists:cuentas_bancarias,id'],
            'cuenta_destino_id' => [
                'required_if:tipo,'.ClubesCajaMovimiento::TIPO_TRANSFERENCIA,
                'nullable',
                'integer',
                'different:cuenta_bancaria_id',
                'exists:cuentas_bancarias,id',
            ],
            'tipo' => ['required', Rule::in([
                ClubesCajaMovimiento::TIPO_INGRESO,
                ClubesCajaMovimiento::TIPO_EGRESO,
                ClubesCajaMovimiento::TIPO_TRANSFERENCIA,
            ])],
            'origen' => ['nullable', Rule::in([
                ClubesCajaMovimiento::ORIGEN_MANUAL,
                ClubesCajaMovimiento::ORIGEN_GASTO,
                ClubesCajaMovimiento::ORIGEN_AJUSTE,
                ClubesCajaMovimiento::ORIGEN_TRANSFERENCIA,
            ])],
            'monto' => ['required', 'numeric', 'min:0.01', 'max:999999999.99'],
            'concepto' => ['required', 'string', 'max:255'],
            'destino' => [
                'required_if:tipo,'.ClubesCajaMovimiento::TIPO_INGRESO,
                'nullable',
                'exclude_if:tipo,'.ClubesCajaMovimiento::TIPO_TRANSFERENCIA,
                Rule::in(ClubesCajaMovimiento::DESTINOS),
            ],
            'evento_id' => [
                'required_if:destino,'.ClubesCajaMovimiento::DESTINO_PARTICIPANTES,
                'nullable',
                'integer',
                'exists:events,id',
            ],
            'persona_id' => [
                'required_if:destino,'.ClubesCajaMovimiento::DESTINO_PARTICIPANTES,
                'nullable',
                'integer',
                'exists:personas,id',
            ],
            'occurred_at' => ['nullable', 'date'],
        ]);

        return ApiResponse::success(
            $this->finanzas->storeMovimiento($request->user(), $data),
            'Movimiento registrado',
        );
    }

    public function destroyMovimiento(Request $request, int $movimiento): JsonResponse
    {
        $this->assertClubesClient($request);
        $this->finanzas->deleteMovimiento($request->user(), $movimiento);

        return ApiResponse::success(null, 'Movimiento eliminado');
    }

    private function assertClubesClient(Request $request): void
    {
        abort_unless(
            $request->header('X-Clubes-Client') === 'clubes',
            403,
            'Las finanzas solo se gestionan desde el front de Clubes.',
        );
    }
}