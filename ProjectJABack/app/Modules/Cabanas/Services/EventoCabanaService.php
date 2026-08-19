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
                        EventoCabanaCama::query()->create([
                            'evento_cabana_cuarto_id' => $ec->id, 'cabana_cama_id' => $cama->id,
                            'codigo' => $cama->codigo, 'nombre' => $cama->nombre,
                            'capacidad' => $cama->capacidad,
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
                $snapshot = $current->get((int) $item['cabana_id'])
                    ?? $this->attach($event, Cabana::query()->findOrFail($item['cabana_id']));
                $snapshot->update(['orden' => $item['orden']]);
            }

            return $this->list($event);
        });
    }
}
