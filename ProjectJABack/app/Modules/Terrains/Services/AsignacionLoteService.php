<?php

namespace App\Modules\Terrains\Services;

use App\Models\User;
use App\Modules\Clubs\Models\Club;
use App\Modules\Events\Models\Event;
use App\Modules\Events\Models\EventoInscripcion;
use App\Modules\Terrains\Models\AsignacionLote;
use App\Modules\Terrains\Models\EventoLote;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class AsignacionLoteService
{
    public function assign(
        EventoLote $lote,
        array $data,
        User $actor,
        bool $canOverrideCapacity = false,
        bool $requireAvailable = false,
    ): AsignacionLote {
        return DB::transaction(function () use ($lote, $data, $actor, $canOverrideCapacity, $requireAvailable) {
            $lote = EventoLote::query()->lockForUpdate()->findOrFail($lote->id);

            if (
                $lote->estado === EventoLote::ESTADO_NO_DISPONIBLE
                || ($requireAvailable && $lote->estado !== EventoLote::ESTADO_DISPONIBLE)
            ) {
                throw ValidationException::withMessages([
                    'lote' => ['El lote no está disponible para asignación.'],
                ]);
            }

            $activa = $lote->asignaciones()->where('estado', AsignacionLote::ESTADO_ACTIVA)->first();
            if ($activa) {
                throw ValidationException::withMessages([
                    'evento_lote_id' => ['El lote ya tiene una asignación activa. Libérala primero.'],
                ]);
            }

            $cantidad = (int) ($data['cantidad_personas'] ?? 0);
            $capacidad = $lote->capacidad_maxima;
            if ($capacidad !== null && $cantidad > $capacidad && ! $canOverrideCapacity) {
                throw ValidationException::withMessages([
                    'cantidad_personas' => ["La cantidad ({$cantidad}) supera la capacidad máxima ({$capacidad})."],
                ]);
            }

            $club = Club::query()->lockForUpdate()->findOrFail((int) $data['club_id']);
            $this->assertClubInscripcionAprobada($lote, $club);
            $this->assertClubHasNoActiveLote($lote, $club);

            $asignacion = AsignacionLote::query()->create([
                'evento_lote_id' => $lote->id,
                'club_id' => (int) $data['club_id'],
                'cantidad_personas' => $cantidad,
                'observaciones' => $data['observaciones'] ?? null,
                'estado' => AsignacionLote::ESTADO_ACTIVA,
                'asignado_por' => $actor->id,
            ]);

            $lote->update(['estado' => EventoLote::ESTADO_ASIGNADO]);

            return $asignacion->load('club:id,organizacion_id,nombre,nombre_corto,logo');
        });
    }

    public function assignForDirector(
        EventoLote $lote,
        Club $club,
        int $cantidadPersonas,
        User $actor,
        ?string $observaciones = null,
    ): AsignacionLote {
        if ($lote->estado !== EventoLote::ESTADO_DISPONIBLE) {
            throw ValidationException::withMessages([
                'lote' => ['El director solo puede elegir lotes disponibles.'],
            ]);
        }

        return $this->assign($lote, [
            'club_id' => $club->id,
            'cantidad_personas' => $cantidadPersonas,
            'observaciones' => $observaciones,
        ], $actor, false, true);
    }

    private function assertClubInscripcionAprobada(EventoLote $lote, Club $club): void
    {
        $lote->loadMissing('eventoTerreno');
        $eventoId = (int) ($lote->eventoTerreno?->evento_id ?? 0);
        if (! $eventoId || ! $club->organizacion_id) {
            throw ValidationException::withMessages([
                'inscripcion' => ['No se pudo validar la inscripción del club para este lote.'],
            ]);
        }

        $rootId = $this->resolveRootEventId($eventoId);
        $aprobada = EventoInscripcion::query()
            ->where('evento_id', $rootId)
            ->where('tipo', 'club')
            ->where('organizacion_id', $club->organizacion_id)
            ->where('estado', EventoInscripcion::ESTADO_APROBADA)
            ->exists();

        if (! $aprobada) {
            throw ValidationException::withMessages([
                'inscripcion' => ['El club debe tener la inscripción aprobada por el supervisor para elegir lote.'],
            ]);
        }
    }

    private function assertClubHasNoActiveLote(
        EventoLote $lote,
        Club $club,
        ?int $ignoreAssignmentId = null,
    ): void {
        $lote->loadMissing('eventoTerreno');
        $eventoId = (int) ($lote->eventoTerreno?->evento_id ?? 0);
        $query = AsignacionLote::query()
            ->where('club_id', $club->id)
            ->where('estado', AsignacionLote::ESTADO_ACTIVA)
            ->whereHas(
                'eventoLote.eventoTerreno',
                fn ($query) => $query->where('evento_id', $eventoId),
            );
        if ($ignoreAssignmentId) {
            $query->where('id', '!=', $ignoreAssignmentId);
        }
        $alreadyAssigned = $query->exists();

        if ($alreadyAssigned) {
            throw ValidationException::withMessages([
                'club' => ['El club ya tiene un lote asignado para este evento.'],
            ]);
        }
    }

    private function resolveRootEventId(int $eventoId): int
    {
        $current = Event::query()->find($eventoId);
        if (! $current) {
            return $eventoId;
        }
        while ($current->evento_padre_id) {
            $current = Event::query()->findOrFail($current->evento_padre_id);
        }

        return (int) $current->id;
    }

    public function liberar(AsignacionLote $asignacion): AsignacionLote
    {
        return DB::transaction(function () use ($asignacion) {
            if ($asignacion->estado !== AsignacionLote::ESTADO_ACTIVA) {
                throw ValidationException::withMessages([
                    'asignacion' => ['La asignación ya está liberada.'],
                ]);
            }

            $asignacion->update(['estado' => AsignacionLote::ESTADO_LIBERADA]);
            $lote = $asignacion->eventoLote;
            if ($lote && ! $lote->asignaciones()->where('estado', AsignacionLote::ESTADO_ACTIVA)->exists()) {
                $lote->update(['estado' => EventoLote::ESTADO_DISPONIBLE]);
            }

            return $asignacion->fresh(['club']);
        });
    }

    public function update(AsignacionLote $asignacion, array $data, bool $canOverrideCapacity = false): AsignacionLote
    {
        if ($asignacion->estado !== AsignacionLote::ESTADO_ACTIVA) {
            throw ValidationException::withMessages([
                'asignacion' => ['Solo se pueden editar asignaciones activas.'],
            ]);
        }

        if (array_key_exists('cantidad_personas', $data)) {
            $cantidad = (int) $data['cantidad_personas'];
            $capacidad = $asignacion->eventoLote?->capacidad_maxima;
            if ($capacidad !== null && $cantidad > $capacidad && ! $canOverrideCapacity) {
                throw ValidationException::withMessages([
                    'cantidad_personas' => ["La cantidad ({$cantidad}) supera la capacidad máxima ({$capacidad})."],
                ]);
            }
        }

        if (array_key_exists('club_id', $data)) {
            $club = Club::query()->findOrFail((int) $data['club_id']);
            $lote = $asignacion->eventoLote;
            $this->assertClubInscripcionAprobada($lote, $club);
            $this->assertClubHasNoActiveLote($lote, $club, $asignacion->id);
        }

        $asignacion->update(array_intersect_key($data, array_flip([
            'club_id',
            'cantidad_personas',
            'observaciones',
        ])));

        return $asignacion->fresh(['club:id,organizacion_id,nombre,nombre_corto,logo']);
    }
}
