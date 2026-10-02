<?php

namespace App\Modules\Settings\Http\Controllers;

use App\Modules\Events\Models\Event;
use App\Modules\Settings\Models\ClubesEventoPresupuestoItem;
use App\Modules\Settings\Services\ClubesPresupuestoService;
use App\Modules\Shared\Http\Responses\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

final class ClubesPresupuestoController
{
    public function __construct(private readonly ClubesPresupuestoService $presupuesto) {}

    public function eventos(Request $request): JsonResponse
    {
        $this->assertClubesClient($request);

        return ApiResponse::success($this->presupuesto->listEventos($request->user()));
    }

    public function show(Request $request, Event $evento): JsonResponse
    {
        $this->assertClubesClient($request);
        $data = $request->validate([
            'presupuesto_id' => ['nullable', 'integer', 'exists:clubes_evento_presupuestos,id'],
        ]);

        return ApiResponse::success($this->presupuesto->show(
            $request->user(),
            $evento,
            isset($data['presupuesto_id']) ? (int) $data['presupuesto_id'] : null,
        ));
    }

    public function update(Request $request, Event $evento): JsonResponse
    {
        $this->assertClubesClient($request);
        $data = $request->validate([
            'presupuesto_id' => ['nullable', 'integer', 'exists:clubes_evento_presupuestos,id'],
            'nombre' => ['nullable', 'string', 'max:120'],
            'activo' => ['nullable', 'boolean'],
            'acompanantes_count' => ['required', 'integer', 'min:0', 'max:9999'],
            'items' => ['present', 'array'],
            'items.*.concepto' => ['required', 'string', 'max:255'],
            'items.*.tipo' => ['required', Rule::in([
                ClubesEventoPresupuestoItem::TIPO_INDIVIDUAL,
                ClubesEventoPresupuestoItem::TIPO_GRUPAL,
            ])],
            'items.*.monto' => ['required', 'numeric', 'min:0', 'max:99999999.99'],
            'items.*.destinatario' => ['required', Rule::in([
                ClubesEventoPresupuestoItem::DEST_MIEMBROS,
                ClubesEventoPresupuestoItem::DEST_ACOMPANANTES,
                ClubesEventoPresupuestoItem::DEST_AMBOS,
            ])],
            'items.*.orden' => ['nullable', 'integer', 'min:0'],
        ]);

        return ApiResponse::success(
            $this->presupuesto->save($request->user(), $evento, $data),
            'Presupuesto guardado',
        );
    }

    public function destroy(Request $request, Event $evento, int $presupuesto): JsonResponse
    {
        $this->assertClubesClient($request);

        return ApiResponse::success(
            $this->presupuesto->destroy($request->user(), $evento, $presupuesto),
            'Presupuesto eliminado',
        );
    }

    private function assertClubesClient(Request $request): void
    {
        abort_unless(
            $request->header('X-Clubes-Client') === 'clubes',
            403,
            'El presupuesto solo se gestiona desde el front de Clubes.',
        );
    }
}
