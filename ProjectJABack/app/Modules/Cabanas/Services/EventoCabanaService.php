<?php

namespace App\Modules\Cabanas\Services;

use App\Modules\Cabanas\Models\AsignacionCama;
use App\Modules\Cabanas\Models\Cabana;
use App\Modules\Cabanas\Models\EventoCabana;
use App\Modules\Cabanas\Models\EventoCabanaCama;
use App\Modules\Cabanas\Models\EventoCabanaCuarto;
use App\Modules\Cabanas\Models\EventoCabanaPiso;
use App\Modules\Events\Models\Event;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class EventoCabanaService
{
    public function list(Event $event): Collection
    {
        return EventoCabana::query()
            ->where('evento_id', $event->id)
            ->with(['pisos.cuartos.camas.asignaciones' => fn ($query) => $query
                ->where('estado', AsignacionCama::ESTADO_ACTIVA)
                ->with('inscripcionPersona:id,persona_id')])
            ->orderBy('orden')
            ->orderBy('id')
            ->get();
    }

    public function attach(Event $event, Cabana $cabana): EventoCabana
    {
        $this->assertEventAllowsCabana($event, $cabana);

        return DB::transaction(function () use ($event, $cabana) {
            $cabana = Cabana::query()->with('pisos.cuartos.camas')->lockForUpdate()->findOrFail($cabana->id);
            if (EventoCabana::query()->where('evento_id', $event->id)->where('cabana_id', $cabana->id)->exists()) {
                throw ValidationException::withMessages(['cabana_id' => ['La cabaña ya está asociada al evento.']]);
            }
            $snapshot = EventoCabana::query()->create([
                'evento_id' => $event->id, 'cabana_id' => $cabana->id, 'nombre' => $cabana->nombre,
                'descripcion' => $cabana->descripcion, 'image_url' => $cabana->image_url,
                'ancho' => $cabana->ancho, 'alto' => $cabana->alto, 'estado' => $cabana->estado,
                'orden' => (int) EventoCabana::query()->where('evento_id', $event->id)->max('orden') + 1,
            ]);
            foreach ($cabana->pisos as $piso) {
                $ep = EventoCabanaPiso::query()->create([
                    'evento_cabana_id' => $snapshot->id, 'cabana_piso_id' => $piso->id,
                    'nombre' => $piso->nombre, 'ancho' => $piso->ancho,
                    'alto' => $piso->alto, 'orden' => $piso->orden,
                ]);
                foreach ($piso->cuartos as $cuarto) {
                    $ec = EventoCabanaCuarto::query()->create([
                        'evento_cabana_piso_id' => $ep->id, 'cabana_cuarto_id' => $cuarto->id,
                        'nombre' => $cuarto->nombre, 'codigo' => $cuarto->codigo,
                        'x' => $cuarto->x, 'y' => $cuarto->y,
                        'ancho' => $cuarto->ancho, 'alto' => $cuarto->alto,
                        'forma' => $cuarto->forma ?? 'rect',
                        'vertices' => $cuarto->vertices,
                        'puertas' => $cuarto->puertas,
                        'genero' => $cuarto->genero, 'capacidad' => $cuarto->capacidad,
                        'orden' => $cuarto->orden,
                    ]);
                    foreach ($cuarto->camas as $cama) {
                        $sugerido = $cama->precio_sugerido !== null ? (float) $cama->precio_sugerido : null;
                        EventoCabanaCama::query()->create([
                            'evento_cabana_cuarto_id' => $ec->id, 'cabana_cama_id' => $cama->id,
                            'codigo' => $cama->codigo, 'nombre' => $cama->nombre,
                            'capacidad' => $cama->capacidad,
                            'tipo' => $cama->tipo ?? 'sencilla',
                            'nivel_camarote' => $cama->nivel_camarote,
                            'grupo_camarote' => $cama->grupo_camarote,
                            'precio_sugerido' => $sugerido,
                            'precio' => $sugerido,
                            'x' => $cama->x, 'y' => $cama->y, 'ancho' => $cama->ancho,
                            'alto' => $cama->alto, 'rotacion' => $cama->rotacion,
                            'estado' => $cama->estado, 'orden' => $cama->orden,
                        ]);
                    }
                }
            }

            return EventoCabana::query()
                ->with(['pisos.cuartos.camas.asignaciones.inscripcionPersona:id,persona_id'])
                ->findOrFail($snapshot->id);
        });
    }

    public function detach(EventoCabana $eventoCabana): void
    {
        $active = AsignacionCama::query()
            ->where('estado', AsignacionCama::ESTADO_ACTIVA)
            ->whereHas('cama.cuarto.piso', fn ($q) => $q->where('evento_cabana_id', $eventoCabana->id))
            ->exists();
        if ($active) {
            throw ValidationException::withMessages(['cabana' => ['Libere las asignaciones activas antes de retirar la cabaña.']]);
        }
        $eventoCabana->delete();
    }

    public function sync(Event $event, array $items): Collection
    {
        return DB::transaction(function () use ($event, $items) {
            $current = EventoCabana::query()
                ->where('evento_id', $event->id)
                ->lockForUpdate()
                ->get()
                ->keyBy('cabana_id');
            $requestedIds = collect($items)->pluck('cabana_id')->map(fn ($id) => (int) $id);

            foreach ($current as $cabanaId => $snapshot) {
                if (! $requestedIds->contains((int) $cabanaId)) {
                    $this->detach($snapshot);
                }
            }

            foreach ($items as $item) {
                $cabana = Cabana::query()->findOrFail($item['cabana_id']);
                $this->assertEventAllowsCabana($event, $cabana);
                $snapshot = $current->get((int) $item['cabana_id'])
                    ?? $this->attach($event, $cabana);
                $snapshot->update(['orden' => $item['orden']]);
            }

            return $this->list($event);
        });
    }

    /**
     * @param  list<array{id: int, precio: float|int|null}>  $items
     */
    public function updateBedPrices(Event $event, array $items): Collection
    {
        return DB::transaction(function () use ($event, $items) {
            foreach ($items as $item) {
                $cama = EventoCabanaCama::query()
                    ->whereKey((int) $item['id'])
                    ->whereHas('cuarto.piso.eventoCabana', fn ($query) => $query->where('evento_id', $event->id))
                    ->lockForUpdate()
                    ->firstOrFail();
                $cama->update([
                    'precio' => isset($item['precio']) ? (float) $item['precio'] : null,
                ]);
            }

            return $this->list($event);
        });
    }

    private function assertEventAllowsCabana(Event $event, Cabana $cabana): void
    {
        if (! $event->usar_cabanas) {
            throw ValidationException::withMessages([
                'cabana_id' => ['Este evento no usa cabañas. Active “Usar cabañas” en el evento.'],
            ]);
        }

        if (! $event->lugar_id) {
            throw ValidationException::withMessages([
                'lugar_id' => ['Seleccione un lugar en el evento antes de asociar cabañas.'],
            ]);
        }

        if ((int) $cabana->lugar_id !== (int) $event->lugar_id) {
            throw ValidationException::withMessages([
                'cabana_id' => ['La cabaña no pertenece al lugar del evento.'],
            ]);
        }
    }
}
