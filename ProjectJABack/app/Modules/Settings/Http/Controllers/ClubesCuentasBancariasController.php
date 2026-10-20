<?php

namespace App\Modules\Settings\Http\Controllers;

use App\Modules\Settings\Models\CuentaBancaria;
use App\Modules\Settings\Services\ClubesCuentasBancariasService;
use App\Modules\Shared\Http\Responses\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

final class ClubesCuentasBancariasController
{
    public function __construct(private readonly ClubesCuentasBancariasService $cuentas) {}

    public function index(Request $request): JsonResponse
    {
        $this->assertClubesClient($request);

        return ApiResponse::success($this->cuentas->list($request->user()));
    }

    public function store(Request $request): JsonResponse
    {
        $this->assertClubesClient($request);

        return ApiResponse::success(
            $this->cuentas->create($request->user(), $this->validated($request)),
            'Cuenta bancaria creada',
            201,
        );
    }

    public function update(Request $request, int $cuenta): JsonResponse
    {
        $this->assertClubesClient($request);

        return ApiResponse::success(
            $this->cuentas->update($request->user(), $cuenta, $this->validated($request)),
            'Cuenta bancaria actualizada',
        );
    }

    public function destroy(Request $request, int $cuenta): JsonResponse
    {
        $this->assertClubesClient($request);
        $this->cuentas->delete($request->user(), $cuenta);

        return ApiResponse::success(null, 'Cuenta bancaria eliminada');
    }

    /** @return array<string, mixed> */
    private function validated(Request $request): array
    {
        return $request->validate([
            'nombre' => ['nullable', 'string', 'max:160'],
            'banco' => ['nullable', 'string', 'max:160'],
            'tipo_cuenta' => ['required', 'string', Rule::in([CuentaBancaria::TIPO_AHORROS, CuentaBancaria::TIPO_CORRIENTE])],
            'numero_cuenta' => ['required', 'string', 'max:64'],
            'titular' => ['required', 'string', 'max:160'],
            'identificacion_titular' => ['nullable', 'string', 'max:32'],
            'activo' => ['sometimes', 'boolean'],
            'orden' => ['nullable', 'integer', 'min:0'],
        ]);
    }

    private function assertClubesClient(Request $request): void
    {
        abort_unless(
            $request->header('X-Clubes-Client') === 'clubes',
            403,
            'Las cuentas bancarias del club solo se gestionan desde Clubes.',
        );
    }
}
