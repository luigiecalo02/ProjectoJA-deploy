<?php

namespace App\Modules\Settings\Services;

use App\Modules\Events\Models\EventoParticipacionAbono;
use App\Modules\Shared\Services\PublicFileService;

final class AbonoPresentacion
{
    public function __construct(private readonly PublicFileService $publicFiles) {}

    /**
     * @return array<string, mixed>
     */
    public function movimiento(EventoParticipacionAbono $abono): array
    {
        return [
            'id' => (int) $abono->id,
            'monto' => (float) $abono->monto,
            'nota' => $abono->nota,
            'estado' => $abono->estado ?: EventoParticipacionAbono::ESTADO_APROBADO,
            'producto_servicio_id' => $abono->producto_servicio_id !== null ? (int) $abono->producto_servicio_id : null,
            'producto_nombre' => $abono->producto?->nombre,
            'comprobante_url' => $this->publicFiles->url($abono->comprobante_path),
            'created_at' => $abono->created_at?->toIso8601String(),
        ];
    }
}
