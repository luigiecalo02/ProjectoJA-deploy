<?php

namespace App\Modules\Settings\Http\Controllers;

use App\Modules\Settings\Models\CuentaBancaria;
use App\Modules\Settings\Services\CuentaBancariaService;
use App\Modules\Shared\Http\Responses\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final class CuentaBancariaController
{
    public function __construct(private readonly CuentaBancariaService $cuentas) {}

    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        $canManage = (bool) $user?->hasPermission('settings.view');
        $canPick = (bool) $user?->hasPermission('events.view')
            || (bool) $user?->hasPermission('events.update')
            || (bool) $user?->hasPermission('events.create');
        abort_unless($canManage || $canPick, Response::HTTP_FORBIDDEN);

        $soloActivas = $canManage ? $request->boolean('activas') : true;

        return ApiResponse::success(
            $this->cuentas->list($soloActivas)->map(fn (CuentaBancaria $cuenta) => $this->cuentas->toPayload($cuenta))->values()
        );
    }

    public function store(Request $request): JsonResponse
    {
        abort_unless($request->user()?->hasPermission('settings.update'), Response::HTTP_FORBIDDEN);

        $cuenta = $this->cuentas->create($this->validated($request));

        return ApiResponse::success($this->cuentas->toPayload($cuenta), 'Cuenta bancaria creada', Response::HTTP_CREATED);
    }

    public function update(Request $request, CuentaBancaria $cuentaBancaria): JsonResponse
    {
        abort_unless($request->user()?->hasPermission('settings.update'), Response::HTTP_FORBIDDEN);

        $cuenta = $this->cuentas->update($cuentaBancaria, $this->validated($request));

        return ApiResponse::success($this->cuentas->toPayload($cuenta), 'Cuenta bancaria actualizada');
    }

    public function destroy(Request $request, CuentaBancaria $cuentaBancaria): JsonResponse
    {
        abort_unless($request->user()?->hasPermission('settings.update'), Response::HTTP_FORBIDDEN);

        $this->cuentas->delete($cuentaBancaria);

        return ApiResponse::success(null, 'Cuenta bancaria eliminada');
    }

    public function uploadQr(Request $request, CuentaBancaria $cuentaBancaria): JsonResponse
    {
        abort_unless($request->user()?->hasPermission('settings.update'), Response::HTTP_FORBIDDEN);

        $data = $request->validate([
            'qr' => ['required', 'file', 'image', 'mimes:jpeg,jpg,png,webp', 'max:5120'],
        ]);

        $cuenta = $this->cuentas->storeQr($cuentaBancaria, $data['qr'], $request->user());

        return ApiResponse::success($this->cuentas->toPayload($cuenta), 'Código QR actualizado');
    }

    public function deleteQr(Request $request, CuentaBancaria $cuentaBancaria): JsonResponse
    {
        abort_unless($request->user()?->hasPermission('settings.update'), Response::HTTP_FORBIDDEN);

        $cuenta = $this->cuentas->deleteQr($cuentaBancaria);

        return ApiResponse::success($this->cuentas->toPayload($cuenta), 'Código QR eliminado');
    }

    /** @return array<string, mixed> */
    private function validated(Request $request): array
    {
        return $request->validate([
            'nombre' => ['required', 'string', 'max:160'],
            'banco' => ['nullable', 'string', 'max:160'],
            'tipo_cuenta' => ['required', 'string', 'in:ahorros,corriente'],
            'numero_cuenta' => ['required', 'string', 'max:64'],
            'titular' => ['nullable', 'string', 'max:160'],
            'identificacion_titular' => ['nullable', 'string', 'max:32'],
            'activo' => ['sometimes', 'boolean'],
            'orden' => ['nullable', 'integer', 'min:0'],
        ]);
    }
}
