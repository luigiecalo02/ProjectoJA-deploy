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
            'evento_id' => ['nullable', 'integer', 'exists:events,id'],
        ]);

        return ApiResponse::success($this->finanzas->resumen(
            $request->user(),
            $data['desde'] ?? null,
            $data['hasta'] ?? null,
            isset($data['evento_id']) ? (int) $data['evento_id'] : null,
        ));
    }

    public function storeMovimiento(Request $request): JsonResponse
    {
        $this->assertClubesClient($request);
        $data = $request->validate([
            'cuenta_id' => ['required', 'integer', 'exists:clubes_caja_cuentas,id'],
            'tipo' => ['required', Rule::in([ClubesCajaMovimiento::TIPO_INGRESO, ClubesCajaMovimiento::TIPO_EGRESO])],
            'origen' => ['nullable', Rule::in([
                ClubesCajaMovimiento::ORIGEN_MANUAL,
                ClubesCajaMovimiento::ORIGEN_GASTO,
                ClubesCajaMovimiento::ORIGEN_AJUSTE,
            ])],
            'monto' => ['required', 'numeric', 'min:0.01', 'max:999999999.99'],
            'concepto' => ['required', 'string', 'max:255'],
            'evento_id' => ['nullable', 'integer', 'exists:events,id'],
            'occurred_at' => ['nullable', 'date'],
        ]);

        return ApiResponse::success(
            $this->finanzas->storeMovimiento($request->user(), $data),
            'Movimiento registrado',
        );
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