<?php

namespace App\Modules\Events\Services;

use App\Models\User;
use App\Modules\Events\Models\EventoInscripcion;
use App\Modules\Events\Models\EventoInscripcionMovimiento;
use Illuminate\Support\Collection;

final class EventInscripcionHistorialService
{
    /** @return array<string, mixed> */
    public function snapshot(EventoInscripcion $inscripcion): array
    {
        $inscripcion->loadMissing([
            'personas.persona',
            'personas.reservas.oferta.producto',
        ]);

        $participantes = $inscripcion->personas
            ->map(function ($linea): array {
                $referencia = $linea->referencia_cliente
                    ?: ($linea->persona_id ? "persona:{$linea->persona_id}" : "linea:{$linea->id}");
                $servicios = $linea->reservas
                    ->map(fn ($reserva) => [
                        'clave' => implode(':', array_filter([
                            $reserva->evento_producto_servicio_id,
                            $reserva->fecha?->toDateString(),
                            $reserva->fecha_inicio?->toDateString(),
                            $reserva->fecha_fin?->toDateString(),
                        ], fn ($value) => $value !== null && $value !== '')),
                        'evento_producto_servicio_id' => (int) $reserva->evento_producto_servicio_id,
                        'producto' => $reserva->oferta?->producto?->nombre,
                        'tipo' => $reserva->oferta?->producto?->tipo,
                        'cantidad' => (int) $reserva->cantidad,
                        'precio_unitario' => (float) $reserva->precio_unitario,
                        'valor_total' => (float) $reserva->valor_total,
                        'fecha' => $reserva->fecha?->toDateString(),
                        'fecha_inicio' => $reserva->fecha_inicio?->toDateString(),
                        'fecha_fin' => $reserva->fecha_fin?->toDateString(),
                    ])
                    ->sortBy('clave')
                    ->values()
                    ->all();

                return [
                    'ref' => $referencia,
                    'persona_id' => $linea->persona_id ? (int) $linea->persona_id : null,
                    'nombre' => $linea->nombre_snapshot ?: $linea->persona?->full_name,
                    'identificacion' => $linea->identificacion_snapshot,
                    'tipo' => $linea->tipo,
                    'cargo_directiva' => $linea->cargo_directiva,
                    'parentesco' => $linea->parentesco,
                    'descuento_nombre' => $linea->descuento_nombre,
                    'descuento_porcentaje' => (float) $linea->descuento_porcentaje,
                    'valor_base' => (float) $linea->valor_base,
                    'valor_descuento' => (float) $linea->valor_descuento,
                    'valor_inscripcion' => (float) $linea->valor_inscripcion,
                    'valor_seguro' => (float) $linea->valor_seguro,
                    'servicios' => $servicios,
                ];
            })
            ->sortBy('ref')
            ->values()
            ->all();

        return [
            'total' => (float) ($inscripcion->total_declarado ?? 0),
            'participantes' => $participantes,
        ];
    }

    /** @param array<string, mixed> $anterior */
    public function registrarCambio(EventoInscripcion $inscripcion, array $anterior, User $actor): void
    {
        $nuevo = $this->snapshot($inscripcion);
        $tieneHistorial = $inscripcion->movimientos()->exists();
        $anteriorTieneDetalle = ($anterior['participantes'] ?? []) !== [];

        if (! $tieneHistorial && $anteriorTieneDetalle) {
            $movimientoInicial = $this->crearMovimiento(
                $inscripcion,
                EventoInscripcionMovimiento::TIPO_INICIAL,
                ['total' => 0.0, 'participantes' => []],
                $anterior,
                $actor,
            );
            $inscripcion->comprobantes()
                ->whereNull('movimiento_id')
                ->update(['movimiento_id' => $movimientoInicial->id]);
        }

        $cambios = $this->calcularCambios($anterior, $nuevo);
        if (! $this->tieneCambios($cambios) && (float) ($anterior['total'] ?? 0) === (float) $nuevo['total']) {
            return;
        }

        $esPrimerMovimiento = ! $inscripcion->movimientos()->exists();
        $movimiento = $this->crearMovimiento(
            $inscripcion,
            $esPrimerMovimiento
                ? EventoInscripcionMovimiento::TIPO_INICIAL
                : EventoInscripcionMovimiento::TIPO_MODIFICACION,
            $anterior,
            $nuevo,
            $actor,
            $cambios,
        );
        if ($esPrimerMovimiento) {
            $inscripcion->comprobantes()
                ->whereNull('movimiento_id')
                ->update(['movimiento_id' => $movimiento->id]);
        }
    }

    /**
     * @param  array<string, mixed>  $anterior
     * @param  array<string, mixed>  $nuevo
     * @param  array<string, mixed>|null  $cambios
     */
    private function crearMovimiento(
        EventoInscripcion $inscripcion,
        string $tipo,
        array $anterior,
        array $nuevo,
        User $actor,
        ?array $cambios = null,
    ): EventoInscripcionMovimiento {
        $totalAnterior = (float) ($anterior['total'] ?? 0);
        $totalNuevo = (float) ($nuevo['total'] ?? 0);

        return EventoInscripcionMovimiento::query()->create([
            'inscripcion_id' => $inscripcion->id,
            'numero' => ((int) $inscripcion->movimientos()->max('numero')) + 1,
            'tipo' => $tipo,
            'total_anterior' => $totalAnterior,
            'total_nuevo' => $totalNuevo,
            'valor_diferencia' => round($totalNuevo - $totalAnterior, 2),
            'snapshot' => $nuevo,
            'cambios' => $cambios ?? $this->calcularCambios($anterior, $nuevo),
            'creado_por' => $actor->id,
        ]);
    }

    /**
     * @param  array<string, mixed>  $anterior
     * @param  array<string, mixed>  $nuevo
     * @return array<string, list<array<string, mixed>>>
     */
    private function calcularCambios(array $anterior, array $nuevo): array
    {
        $antes = collect($anterior['participantes'] ?? [])->keyBy('ref');
        $despues = collect($nuevo['participantes'] ?? [])->keyBy('ref');
        $participantesAgregados = $despues->only($despues->keys()->diff($antes->keys())->all())
            ->map(fn (array $item) => $this->sinServicios($item))->values()->all();
        $participantesRetirados = $antes->only($antes->keys()->diff($despues->keys())->all())
            ->map(fn (array $item) => $this->sinServicios($item))->values()->all();
        $participantesModificados = [];

        foreach ($antes->keys()->intersect($despues->keys()) as $ref) {
            $previo = $this->sinServicios($antes[$ref]);
            $actual = $this->sinServicios($despues[$ref]);
            if ($previo !== $actual) {
                $participantesModificados[] = [
                    'ref' => $ref,
                    'nombre' => $actual['nombre'] ?? $previo['nombre'] ?? null,
                    'anterior' => $previo,
                    'nuevo' => $actual,
                ];
            }
        }

        $serviciosAntes = $this->serviciosIndexados($antes);
        $serviciosDespues = $this->serviciosIndexados($despues);
        $serviciosAgregados = $serviciosDespues
            ->only($serviciosDespues->keys()->diff($serviciosAntes->keys())->all())->values()->all();
        $serviciosRetirados = $serviciosAntes
            ->only($serviciosAntes->keys()->diff($serviciosDespues->keys())->all())->values()->all();
        $serviciosModificados = [];

        foreach ($serviciosAntes->keys()->intersect($serviciosDespues->keys()) as $clave) {
            if ($serviciosAntes[$clave] !== $serviciosDespues[$clave]) {
                $serviciosModificados[] = [
                    'clave' => $clave,
                    'anterior' => $serviciosAntes[$clave],
                    'nuevo' => $serviciosDespues[$clave],
                ];
            }
        }

        return [
            'participantes_agregados' => $participantesAgregados,
            'participantes_retirados' => $participantesRetirados,
            'participantes_modificados' => $participantesModificados,
            'servicios_agregados' => $serviciosAgregados,
            'servicios_retirados' => $serviciosRetirados,
            'servicios_modificados' => $serviciosModificados,
        ];
    }

    /** @param array<string, mixed> $participante
     * @return array<string, mixed>
     */
    private function sinServicios(array $participante): array
    {
        unset($participante['servicios']);

        return $participante;
    }

    /**
     * @param  Collection<string, array<string, mixed>>  $participantes
     * @return Collection<string, array<string, mixed>>
     */
    private function serviciosIndexados(Collection $participantes): Collection
    {
        return $participantes->flatMap(function (array $participante) {
            return collect($participante['servicios'] ?? [])->mapWithKeys(function (array $servicio) use ($participante) {
                $clave = "{$participante['ref']}|{$servicio['clave']}";

                return [$clave => [
                    ...$servicio,
                    'participante_ref' => $participante['ref'],
                    'participante_nombre' => $participante['nombre'],
                ]];
            });
        });
    }

    /** @param array<string, list<array<string, mixed>>> $cambios */
    private function tieneCambios(array $cambios): bool
    {
        return collect($cambios)->contains(fn (array $items) => $items !== []);
    }
}
