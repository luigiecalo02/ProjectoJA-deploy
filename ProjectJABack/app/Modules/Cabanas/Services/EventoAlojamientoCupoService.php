<?php

namespace App\Modules\Cabanas\Services;

use App\Models\User;
use App\Modules\Cabanas\Models\AsignacionCama;
use App\Modules\Cabanas\Models\EventoAlojamientoCupo;
use App\Modules\Cabanas\Models\EventoCabanaCama;
use App\Modules\Events\Models\Event;
use App\Modules\Events\Models\EventoInscripcion;
use App\Modules\Events\Models\EventoInscripcionPersona;
use App\Modules\Events\Models\EventoPago;
use App\Modules\Events\Models\EventoServicioReserva;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class EventoAlojamientoCupoService
{
    public function __construct(
        private readonly AsignacionCamaService $asignaciones,
        private readonly ElegibilidadCamaService $elegibilidad,
    ) {}

    public function capacity(Event $event): int
    {
        return (int) EventoCabanaCama::query()
            ->whereHas('cuarto.piso.eventoCabana', fn ($query) => $query->where('evento_id', $event->id))
            ->sum('capacidad');
    }

    public function occupied(Event $event): int
    {
        return AsignacionCama::query()
            ->where('evento_id', $event->id)
            ->where('estado', AsignacionCama::ESTADO_ACTIVA)
            ->count();
    }

    public function reservedOpen(Event $event, bool $lock = false): int
    {
        $query = EventoAlojamientoCupo::query()
            ->where('evento_id', $event->id)
            ->where('estado', EventoAlojamientoCupo::ESTADO_ABIERTO);
        if ($lock) {
            $query->lockForUpdate();
        }

        return $query->get()->sum(fn (EventoAlojamientoCupo $cupo) => $cupo->restantes());
    }

    /**
     * @return array{capacidad: int, ocupadas: int, reservados: int, libres: int}
     */
    public function pool(Event $event): array
    {
        $capacidad = $this->capacity($event);
        $ocupadas = $this->occupied($event);
        $reservados = $this->reservedOpen($event);

        return [
            'capacidad' => $capacidad,
            'ocupadas' => $ocupadas,
            'reservados' => $reservados,
            'libres' => max(0, $capacidad - $ocupadas - $reservados),
        ];
    }

    public function cupoForUser(Event $event, User $user): ?EventoAlojamientoCupo
    {
        return EventoAlojamientoCupo::query()
            ->where('evento_id', $event->id)
            ->where('user_id', $user->id)
            ->first();
    }

    /**
     * @return Collection<int, EventoAlojamientoCupo>
     */
    public function list(Event $event): Collection
    {
        return EventoAlojamientoCupo::query()
            ->where('evento_id', $event->id)
            ->with('user:id,name,email')
            ->orderBy('id')
            ->get();
    }

    /**
     * @param  list<array{user_id: int, cupos: int}>  $items
     * @return Collection<int, EventoAlojamientoCupo>
     */
    public function sync(Event $event, array $items, User $actor): Collection
    {
        return DB::transaction(function () use ($event, $items, $actor) {
            $existing = EventoAlojamientoCupo::query()
                ->where('evento_id', $event->id)
                ->lockForUpdate()
                ->get()
                ->keyBy('user_id');
            $keep = [];
            $usedByKept = 0;

            foreach ($items as $item) {
                $userId = (int) $item['user_id'];
                $cupos = (int) $item['cupos'];
                $current = $existing->get($userId);
                $used = $current?->usados() ?? 0;
                if ($cupos < $used) {
                    throw ValidationException::withMessages([
                        'items' => ["No se pueden dejar menos de {$used} cupos: ya hay personas asignadas."],
                    ]);
                }
                if ($current) {
                    $current->update(['cupos' => $cupos]);
                    $keep[] = $current->id;
                    $usedByKept += $used;
                    continue;
                }
                $created = EventoAlojamientoCupo::query()->create([
                    'evento_id' => $event->id,
                    'user_id' => $userId,
                    'cupos' => $cupos,
                    'estado' => EventoAlojamientoCupo::ESTADO_ABIERTO,
                    'created_by' => $actor->id,
                ]);
                $keep[] = $created->id;
            }

            EventoAlojamientoCupo::query()
                ->where('evento_id', $event->id)
                ->when($keep !== [], fn ($query) => $query->whereNotIn('id', $keep), fn ($query) => $query)
                ->get()
                ->each(fn (EventoAlojamientoCupo $cupo) => $cupo->delete());

            $capacity = $this->capacity($event);
            $occupied = $this->occupied($event);
            $sumCupos = collect($items)->sum(fn (array $item) => (int) $item['cupos']);
            $otherOccupied = max(0, $occupied - $usedByKept);
            if ($otherOccupied + $sumCupos > $capacity) {
                throw ValidationException::withMessages([
                    'items' => ['La suma de cupos no puede superar la capacidad libre de las cabañas del evento.'],
                ]);
            }

            return $this->list($event);
        });
    }

    /**
     * @return list<array{id: int, nombre: string, identificacion: string|null, sexo: string|null}>
     */
    public function candidates(Event $event): array
    {
        $assigned = AsignacionCama::query()
            ->where('evento_id', $event->id)
            ->where('estado', AsignacionCama::ESTADO_ACTIVA)
            ->pluck('inscripcion_persona_id');

        return EventoInscripcionPersona::query()
            ->where('estado', EventoInscripcionPersona::ESTADO_CONFIRMADA)
            ->whereHas('inscripcion', fn ($query) => $query
                ->where('evento_id', $event->id)
                ->where('estado', EventoInscripcion::ESTADO_APROBADA))
            ->whereNotIn('id', $assigned)
            ->with('persona:id,sexo')
            ->orderBy('nombre_snapshot')
            ->get()
            ->map(fn (EventoInscripcionPersona $linea) => [
                'id' => $linea->id,
                'nombre' => $linea->nombre_snapshot,
                'identificacion' => $linea->identificacion_snapshot,
                'sexo' => $linea->persona?->sexo,
            ])
            ->values()
            ->all();
    }

    public function assignFromCupo(
        EventoAlojamientoCupo $cupo,
        EventoCabanaCama $cama,
        EventoInscripcionPersona $linea,
        User $actor,
    ): AsignacionCama {
        return DB::transaction(function () use ($cupo, $cama, $linea, $actor) {
            $cupo = EventoAlojamientoCupo::query()->lockForUpdate()->findOrFail($cupo->id);
            if (! $cupo->isOpen()) {
                throw ValidationException::withMessages(['cupo' => ['El cupo de alojamiento ya está cerrado.']]);
            }
            $event = $cupo->evento()->firstOrFail();
            $cama->loadMissing('cuarto.piso.eventoCabana');
            if ((int) $cama->cuarto?->piso?->eventoCabana?->evento_id !== (int) $event->id) {
                throw ValidationException::withMessages(['cama' => ['La cama no pertenece a este evento.']]);
            }
            $this->elegibilidad->assertApprovedLine($event, $linea);
            $actual = AsignacionCama::query()
                ->where('evento_id', $event->id)
                ->where('inscripcion_persona_id', $linea->id)
                ->where('estado', AsignacionCama::ESTADO_ACTIVA)
                ->lockForUpdate()
                ->first();
            $movingOwn = $actual && (int) $actual->evento_alojamiento_cupo_id === (int) $cupo->id;
            if (! $movingOwn && $cupo->usados() >= (int) $cupo->cupos) {
                throw ValidationException::withMessages(['cupo' => ['Ya usaste todos los cupos reservados.']]);
            }
            $reserva = $this->optionalReserva($event, $linea);

            return $this->asignaciones->assignLine($cama, $linea, $actor, $reserva, $cupo->id);
        });
    }

    public function close(EventoAlojamientoCupo $cupo, User $actor): EventoAlojamientoCupo
    {
        return DB::transaction(function () use ($cupo, $actor) {
            $cupo = EventoAlojamientoCupo::query()->lockForUpdate()->findOrFail($cupo->id);
            if ($cupo->isOpen()) {
                $cupo->update([
                    'estado' => EventoAlojamientoCupo::ESTADO_CERRADO,
                    'cerrado_at' => now(),
                    'created_by' => $cupo->created_by ?: $actor->id,
                ]);
            }

            return $cupo->fresh() ?? $cupo;
        });
    }

    /**
     * @return array{
     *     id: int,
     *     user_id: int,
     *     cupos: int,
     *     usados: int,
     *     restantes: int,
     *     estado: string,
     *     cerrado_at: string|null,
     *     user: array{id: int, name: string, email: string}|null,
     *     asignaciones?: list<array<string, mixed>>
     * }
     */
    public function payload(EventoAlojamientoCupo $cupo, bool $withAssignments = false): array
    {
        $usados = $cupo->usados();
        $user = $cupo->user;
        $data = [
            'id' => $cupo->id,
            'user_id' => $cupo->user_id,
            'cupos' => (int) $cupo->cupos,
            'usados' => $usados,
            'restantes' => max(0, (int) $cupo->cupos - $usados),
            'estado' => $cupo->estado,
            'cerrado_at' => $cupo->cerrado_at?->toIso8601String(),
            'user' => $user ? [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
            ] : null,
        ];
        if ($withAssignments) {
            $data['asignaciones'] = $cupo->asignaciones()
                ->where('estado', AsignacionCama::ESTADO_ACTIVA)
                ->with(['inscripcionPersona:id,nombre_snapshot', 'cama:id,codigo,nombre'])
                ->get()
                ->map(fn (AsignacionCama $asignacion) => [
                    'id' => $asignacion->id,
                    'inscripcion_persona_id' => $asignacion->inscripcion_persona_id,
                    'nombre' => $asignacion->inscripcionPersona?->nombre_snapshot,
                    'cama' => $asignacion->cama ? [
                        'id' => $asignacion->cama->id,
                        'codigo' => $asignacion->cama->codigo,
                        'nombre' => $asignacion->cama->nombre,
                    ] : null,
                ])
                ->values()
                ->all();
        }

        return $data;
    }

    private function optionalReserva(Event $event, EventoInscripcionPersona $linea): ?EventoServicioReserva
    {
        return EventoServicioReserva::query()
            ->where('evento_id', $event->id)
            ->where('inscripcion_persona_id', $linea->id)
            ->where('estado', EventoServicioReserva::ESTADO_CONFIRMADA)
            ->whereHas('oferta.producto', fn ($query) => $query->where('tipo', 'CABANA'))
            ->whereHas('pagos', fn ($query) => $query->where('estado', EventoPago::ESTADO_PAGADO))
            ->first();
    }
}
