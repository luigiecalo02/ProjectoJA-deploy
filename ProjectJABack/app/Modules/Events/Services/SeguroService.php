<?php

namespace App\Modules\Events\Services;

use App\Modules\Clubs\Models\Persona;
use App\Modules\Events\Models\Event;
use App\Modules\Events\Models\EventoInscripcion;
use App\Modules\Events\Models\EventoInscripcionComprobante;
use App\Modules\Events\Models\EventoPago;
use App\Modules\Events\Models\Seguro;
use App\Modules\Events\Models\TipoSeguro;
use Carbon\Carbon;
use Illuminate\Support\Collection;

final class SeguroService
{
    /**
     * @return array{cubierta: bool, estado: string, motivo: string|null, seguro: Seguro|null}
     */
    public function estaCubierta(Persona|int $persona, Event|int $evento): array
    {
        $personaId = $persona instanceof Persona ? (int) $persona->id : (int) $persona;
        $eventoModel = $evento instanceof Event
            ? $evento
            : Event::query()->findOrFail($evento);

        if (! $eventoModel->requiere_seguro) {
            return [
                'cubierta' => true,
                'estado' => 'NO_REQUIERE',
                'motivo' => 'El evento no requiere seguro.',
                'seguro' => null,
            ];
        }

        $inicioEvento = Carbon::parse($eventoModel->starts_at)->startOfDay();
        $finEvento = Carbon::parse($eventoModel->ends_at)->startOfDay();

        $seguros = Seguro::query()
            ->with('tipoSeguro')
            ->where('persona_id', $personaId)
            ->where('estado', Seguro::ESTADO_ACTIVO)
            ->whereDate('fecha_inicio', '<=', $finEvento)
            ->whereDate('fecha_fin', '>=', $inicioEvento)
            ->orderByDesc('fecha_fin')
            ->get();

        foreach ($seguros as $seguro) {
            $tipo = $seguro->tipoSeguro?->tipo;
            if ($tipo === TipoSeguro::TIPO_ANUAL) {
                if ($this->cubreRango($seguro, $inicioEvento, $finEvento)) {
                    return [
                        'cubierta' => true,
                        'estado' => 'ASEGURADO',
                        'motivo' => 'Seguro anual vigente.',
                        'seguro' => $seguro,
                    ];
                }
            }
            if ($tipo === TipoSeguro::TIPO_EVENTO) {
                if ($this->cubreRango($seguro, $inicioEvento, $finEvento)) {
                    return [
                        'cubierta' => true,
                        'estado' => 'ASEGURADO',
                        'motivo' => 'Seguro vigente que cubre las fechas del evento.',
                        'seguro' => $seguro,
                    ];
                }
            }
        }

        return [
            'cubierta' => false,
            'estado' => 'SIN_SEGURO',
            'motivo' => 'No tiene seguro vigente para este evento.',
            'seguro' => null,
        ];
    }

    /**
     * @param  array<int>  $personaIds
     * @return array<int, array{cubierta: bool, estado: string, motivo: string|null, seguro_id: int|null}>
     */
    public function coberturaBatch(array $personaIds, Event $evento): array
    {
        $out = [];
        foreach ($personaIds as $id) {
            $r = $this->estaCubierta((int) $id, $evento);
            $out[(int) $id] = [
                'cubierta' => $r['cubierta'],
                'estado' => $r['estado'],
                'motivo' => $r['motivo'],
                'seguro_id' => $r['seguro']?->id,
            ];
        }

        return $out;
    }

    public function activarPendientesSiInscripcionPagada(EventoInscripcion $inscripcion): bool
    {
        $totalAprobado = (float) $inscripcion->comprobantes()
            ->where('estado', EventoInscripcionComprobante::ESTADO_APROBADO)
            ->sum('valor');
        $totalDeclarado = (float) ($inscripcion->total_declarado ?? 0);

        if ($totalDeclarado <= 0 || $totalAprobado + 0.01 < $totalDeclarado) {
            return false;
        }

        $segurosPendientes = Seguro::query()
            ->where('inscripcion_id', $inscripcion->id)
            ->where('estado', Seguro::ESTADO_PENDIENTE)
            ->pluck('id');

        if ($segurosPendientes->isEmpty()) {
            return false;
        }

        Seguro::query()
            ->whereIn('id', $segurosPendientes)
            ->update([
                'estado' => Seguro::ESTADO_ACTIVO,
                'fecha_pago' => now(),
            ]);

        EventoPago::query()
            ->where('pagable_type', Seguro::class)
            ->whereIn('pagable_id', $segurosPendientes)
            ->where('estado', EventoPago::ESTADO_PENDIENTE)
            ->update([
                'estado' => EventoPago::ESTADO_PAGADO,
                'pagado_at' => now(),
            ]);

        return true;
    }

    public function listTipos(bool $soloActivos = true): Collection
    {
        $q = TipoSeguro::query()->orderBy('nombre');
        if ($soloActivos) {
            $q->where('activo', true);
        }

        return $q->get();
    }

    private function cubreRango(Seguro $seguro, Carbon $inicio, Carbon $fin): bool
    {
        $sIni = Carbon::parse($seguro->fecha_inicio)->startOfDay();
        $sFin = Carbon::parse($seguro->fecha_fin)->startOfDay();

        return $sIni->lte($inicio) && $sFin->gte($fin);
    }
}
