<?php

namespace App\Modules\Cabanas\Services;

use App\Models\User;
use App\Modules\Cabanas\Events\OcupacionCabanaChanged;
use App\Modules\Cabanas\Models\AsignacionCama;
use App\Modules\Cabanas\Models\EventoAlojamientoCupo;
use App\Modules\Cabanas\Models\EventoCabanaCama;
use App\Modules\Events\Models\Event;
use App\Modules\Events\Models\EventoInscripcion;
use App\Modules\Events\Models\EventoInscripcionPersona;
use App\Modules\Events\Models\EventoServicioReserva;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class AsignacionCamaService
{
    public function __construct(private readonly ElegibilidadCamaService $elegibilidad) {}

    public function selfAssign(EventoCabanaCama $cama, User $actor): AsignacionCama
    {
        $event = $this->eventFor($cama);
        [$linea, $reserva] = $this->elegibilidad->resolveIndividual($actor, $event);

        return $this->assign($cama, $linea, $reserva, $actor);
    }

    public function assignFor(EventoCabanaCama $cama, EventoInscripcionPersona $linea, User $actor): AsignacionCama
    {
        $event = $this->eventFor($cama);
        [$linea, $reserva] = $this->elegibilidad->resolveForLine($event, $linea);

        return $this->assign($cama, $linea, $reserva, $actor);
    }

    public function assignLine(
        EventoCabanaCama $cama,
        EventoInscripcionPersona $linea,
        User $actor,
        ?EventoServicioReserva $reserva = null,
        ?int $cupoId = null,
    ): AsignacionCama {
        return $this->assign($cama, $linea, $reserva, $actor, $cupoId);
    }

    private function assign(
        EventoCabanaCama $cama,
        EventoInscripcionPersona $linea,
        ?EventoServicioReserva $reserva,
        User $actor,
        ?int $cupoId = null,
    ): AsignacionCama {
        $result = DB::transaction(function () use ($cama, $linea, $reserva, $actor, $cupoId) {
            $linea = EventoInscripcionPersona::query()->lockForUpdate()->findOrFail($linea->id);
            if ($reserva) {
                $reserva = EventoServicioReserva::query()->lockForUpdate()->findOrFail($reserva->id);
            }
            $cama = EventoCabanaCama::query()->lockForUpdate()->findOrFail($cama->id);
            $event = $this->eventFor($cama);
            if ($reserva) {
                [, $reserva] = $this->elegibilidad->resolveForLine($event, $linea);
            } else {
                $this->elegibilidad->assertApprovedLine($event, $linea);
            }
            if ($cama->estado !== 'disponible') {
                throw ValidationException::withMessages(['cama' => ['La cama no está disponible.']]);
            }
            $this->elegibilidad->assertCompatible($cama, $linea);
            $actual = AsignacionCama::query()
                ->where('evento_id', $event->id)
                ->where('inscripcion_persona_id', $linea->id)
                ->where('estado', AsignacionCama::ESTADO_ACTIVA)
                ->lockForUpdate()
                ->first();
            if ($actual && (int) $actual->evento_cabana_cama_id === (int) $cama->id) {
                return $actual;
            }
            $ocupacion = AsignacionCama::query()->where('evento_cabana_cama_id', $cama->id)
                ->where('estado', AsignacionCama::ESTADO_ACTIVA)->lockForUpdate()->count();
            if ($ocupacion >= $cama->capacidad) {
                throw ValidationException::withMessages(['cama' => ['La cama alcanzó su capacidad.']]);
            }
            if ($cupoId === null && ! $actual) {
                $this->assertPublicPool($event);
            }
            if ($actual) {
                $actual->update(['estado' => AsignacionCama::ESTADO_LIBERADA, 'liberada_at' => now()]);
            }

            return AsignacionCama::query()->create([
                'evento_id' => $event->id,
                'evento_cabana_cama_id' => $cama->id,
                'inscripcion_persona_id' => $linea->id,
                'evento_servicio_reserva_id' => $reserva?->id,
                'evento_alojamiento_cupo_id' => $cupoId,
                'estado' => AsignacionCama::ESTADO_ACTIVA,
                'asignado_por' => $actor->id,
            ]);
        });
        $this->broadcast($result, 'assigned');

        return $result->load('cama.cuarto.piso.eventoCabana');
    }

    private function assertPublicPool(Event $event): void
    {
        $capacidad = (int) EventoCabanaCama::query()
            ->whereHas('cuarto.piso.eventoCabana', fn ($query) => $query->where('evento_id', $event->id))
            ->sum('capacidad');
        $ocupadas = AsignacionCama::query()
            ->where('evento_id', $event->id)
            ->where('estado', AsignacionCama::ESTADO_ACTIVA)
            ->lockForUpdate()
            ->count();
        $reservados = EventoAlojamientoCupo::query()
            ->where('evento_id', $event->id)
            ->where('estado', EventoAlojamientoCupo::ESTADO_ABIERTO)
            ->lockForUpdate()
            ->get()
            ->sum(fn (EventoAlojamientoCupo $cupo) => $cupo->restantes());
        if ($ocupadas + 1 + $reservados > $capacidad) {
            throw ValidationException::withMessages(['cama' => ['No hay cupos libres de alojamiento.']]);
        }
    }

    public function release(AsignacionCama $asignacion): AsignacionCama
    {
        $result = DB::transaction(function () use ($asignacion) {
            $asignacion = AsignacionCama::query()->lockForUpdate()->findOrFail($asignacion->id);
            if ($asignacion->estado === AsignacionCama::ESTADO_ACTIVA) {
                $asignacion->update(['estado' => AsignacionCama::ESTADO_LIBERADA, 'liberada_at' => now()]);
            }

            return $asignacion;
        });
        $this->broadcast($result, 'released');

        return $result;
    }

    public function releaseByReservation(EventoServicioReserva $reserva): void
    {
        AsignacionCama::query()
            ->where('evento_servicio_reserva_id', $reserva->id)
            ->where('estado', AsignacionCama::ESTADO_ACTIVA)
            ->each(fn (AsignacionCama $asignacion) => $this->release($asignacion));
    }

    public function releaseByInscripcion(EventoInscripcion $inscripcion): void
    {
        AsignacionCama::query()
            ->where('evento_id', $inscripcion->evento_id)
            ->where('estado', AsignacionCama::ESTADO_ACTIVA)
            ->whereHas('inscripcionPersona', fn ($query) => $query->where('inscripcion_id', $inscripcion->id))
            ->each(fn (AsignacionCama $asignacion) => $this->release($asignacion));
    }

    private function eventFor(EventoCabanaCama $cama): Event
    {
        $cama->loadMissing('cuarto.piso.eventoCabana.evento');

        return $cama->cuarto?->piso?->eventoCabana?->evento
            ?? throw ValidationException::withMessages(['cama' => ['La cama no pertenece a un evento válido.']]);
    }

    private function broadcast(AsignacionCama $asignacion, string $action): void
    {
        $ocupacion = AsignacionCama::query()->where('evento_cabana_cama_id', $asignacion->evento_cabana_cama_id)
            ->where('estado', AsignacionCama::ESTADO_ACTIVA)->count();
        OcupacionCabanaChanged::dispatch(
            (int) $asignacion->evento_id,
            (int) $asignacion->evento_cabana_cama_id,
            $ocupacion,
            $action,
        );
    }
}
