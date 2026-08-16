<?php

namespace App\Modules\Terrains\Services;

use App\Modules\Events\Models\Event;
use App\Modules\Terrains\Models\ConfiguracionTerreno;
use App\Modules\Terrains\Models\EventoEstructura;
use App\Modules\Terrains\Models\EventoLote;
use App\Modules\Terrains\Models\EventoTerreno;
use App\Modules\Terrains\Models\EventoZona;
use App\Modules\Terrains\Models\Terreno;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class DistribucionEventoService
{
    public function __construct(private readonly GeometriaService $geometria) {}

    public function getDistribucion(Event $event): ?EventoTerreno
    {
        return EventoTerreno::query()
            ->where('evento_id', $event->id)
            ->with([
                'terreno',
                'configuracion:id,nombre,es_default',
                'zonas.lotes.asignaciones' => fn ($q) => $q->where('estado', 'activa')->with('club:id,organizacion_id,nombre,nombre_corto,logo'),
                'lotesSinZona.asignaciones' => fn ($q) => $q->where('estado', 'activa')->with('club:id,organizacion_id,nombre,nombre_corto,logo'),
                'estructuras',
            ])
            ->first();
    }

    public function attachTerreno(
        Event $event,
        Terreno $terreno,
        ConfiguracionTerreno $config,
        ?string $descripcion = null,
    ): EventoTerreno {
        if ((int) $config->terreno_id !== (int) $terreno->id) {
            throw ValidationException::withMessages([
                'configuracion_terreno_id' => ['La configuración no pertenece al terreno seleccionado.'],
            ]);
        }

        if (EventoTerreno::query()->where('evento_id', $event->id)->exists()) {
            throw ValidationException::withMessages([
                'terreno_id' => ['El evento ya tiene un terreno asociado. Desasócialo antes de asignar otro.'],
            ]);
        }

        return DB::transaction(function () use ($event, $terreno, $config, $descripcion) {
            $eventoTerreno = EventoTerreno::query()->create([
                'evento_id' => $event->id,
                'terreno_id' => $terreno->id,
                'configuracion_terreno_id' => $config->id,
                'descripcion' => $descripcion,
                'estado' => 'activo',
            ]);

            $this->cloneFromBase($eventoTerreno, $terreno, $config);

            return $this->findEventoTerreno($eventoTerreno->id);
        });
    }

    public function detach(Event $event): void
    {
        $eventoTerreno = EventoTerreno::query()->where('evento_id', $event->id)->first();
        if (! $eventoTerreno) {
            return;
        }

        $hasActive = EventoLote::query()
            ->whereHas('eventoZona', fn ($q) => $q->where('evento_terreno_id', $eventoTerreno->id))
            ->whereHas('asignaciones', fn ($q) => $q->where('estado', 'activa'))
            ->exists();

        if ($hasActive) {
            throw ValidationException::withMessages([
                'evento' => ['No se puede desasociar el terreno mientras existan asignaciones activas.'],
            ]);
        }

        $eventoTerreno->delete();
    }

    public function findEventoTerreno(int $id): EventoTerreno
    {
        return EventoTerreno::query()
            ->with([
                'terreno',
                'configuracion:id,nombre,es_default',
                'zonas.lotes.asignaciones' => fn ($q) => $q->where('estado', 'activa')->with('club:id,organizacion_id,nombre,nombre_corto,logo'),
                'lotesSinZona.asignaciones' => fn ($q) => $q->where('estado', 'activa')->with('club:id,organizacion_id,nombre,nombre_corto,logo'),
                'estructuras',
            ])
            ->findOrFail($id);
    }

    public function createZona(EventoTerreno $eventoTerreno, array $data): EventoZona
    {
        $this->geometria->validate($data['geometria'] ?? null);
        $measures = $this->geometria->measure($data['geometria'] ?? null);

        $zona = EventoZona::query()->create([
            'evento_terreno_id' => $eventoTerreno->id,
            'zona_terreno_id' => $data['zona_terreno_id'] ?? null,
            'nombre' => $data['nombre'],
            'geometria' => $data['geometria'] ?? null,
            'area' => $data['area'] ?? $measures['area'],
            'perimetro' => $data['perimetro'] ?? $measures['perimetro'],
            'capacidad' => $data['capacidad'] ?? null,
            'color' => $data['color'] ?? null,
            'orden' => $data['orden'] ?? (($eventoTerreno->zonas()->max('orden') ?? 0) + 1),
            'estado' => $data['estado'] ?? 'activo',
        ]);

        return EventoZona::query()->with('lotes.asignaciones')->findOrFail($zona->id);
    }

    public function updateZona(EventoZona $zona, array $data): EventoZona
    {
        if (array_key_exists('geometria', $data)) {
            $this->geometria->validate($data['geometria']);
            $measures = $this->geometria->measure($data['geometria']);
            $data['area'] = $data['area'] ?? $measures['area'];
            $data['perimetro'] = $data['perimetro'] ?? $measures['perimetro'];
        }

        $zona->update($data);

        return EventoZona::query()->with('lotes.asignaciones')->findOrFail($zona->id);
    }

    public function deleteZona(EventoZona $zona): void
    {
        $hasActive = $zona->lotes()
            ->whereHas('asignaciones', fn ($q) => $q->where('estado', 'activa'))
            ->exists();

        if ($hasActive) {
            throw ValidationException::withMessages([
                'zona' => ['No se puede eliminar una zona con lotes asignados activamente.'],
            ]);
        }

        $zona->lotes()->delete();
        $zona->delete();
    }

    public function createLote(EventoZona $zona, array $data): EventoLote
    {
        $zona->loadMissing('eventoTerreno.terreno', 'eventoTerreno.estructuras');
        $this->geometria->validate($data['geometria'] ?? null);
        $this->assertNoEstructuraOverlap($zona->eventoTerreno, $data['geometria'] ?? null);
        $measures = $this->geometria->measure($data['geometria'] ?? null);
        $metros = (float) ($zona->eventoTerreno?->terreno?->metros_por_persona ?? 10);
        $capacidadCalc = $this->geometria->capacidadFromArea($measures['area'] ?? ($data['area'] ?? null), $metros);
        $tipo = $data['tipo_capacidad'] ?? 'calculada';
        $capacidadMax = $tipo === 'manual'
            ? ($data['capacidad_maxima'] ?? $capacidadCalc)
            : ($capacidadCalc ?? ($data['capacidad_maxima'] ?? null));

        $lote = EventoLote::query()->create([
            'evento_terreno_id' => $zona->evento_terreno_id,
            'evento_zona_id' => $zona->id,
            'lote_terreno_id' => $data['lote_terreno_id'] ?? null,
            'codigo' => $data['codigo'],
            'nombre' => $data['nombre'] ?? null,
            'geometria' => $data['geometria'] ?? null,
            'area' => $data['area'] ?? $measures['area'],
            'perimetro' => $data['perimetro'] ?? $measures['perimetro'],
            'capacidad_calculada' => $capacidadCalc,
            'capacidad_maxima' => $capacidadMax,
            'tipo_capacidad' => $tipo,
            'orden' => $data['orden'] ?? (($zona->lotes()->max('orden') ?? 0) + 1),
            'estado' => $data['estado'] ?? EventoLote::ESTADO_DISPONIBLE,
        ]);

        return EventoLote::query()->with(['asignaciones' => fn ($q) => $q->where('estado', 'activa')->with('club')])->findOrFail($lote->id);
    }

    public function createLoteOnTerreno(EventoTerreno $eventoTerreno, array $data): EventoLote
    {
        $eventoTerreno->loadMissing('terreno', 'estructuras');
        $this->geometria->validate($data['geometria'] ?? null, true);
        if (empty($eventoTerreno->terreno?->geometria)) {
            throw ValidationException::withMessages([
                'geometria' => ['Debes dibujar primero el área del terreno.'],
            ]);
        }
        if (! $this->geometria->isContained($data['geometria'] ?? null, $eventoTerreno->terreno->geometria)) {
            throw ValidationException::withMessages([
                'geometria' => ['El lote no puede estar fuera del área del terreno.'],
            ]);
        }
        $this->assertNoEstructuraOverlap($eventoTerreno, $data['geometria'] ?? null);

        $measures = $this->geometria->measure($data['geometria'] ?? null);
        $metros = (float) ($eventoTerreno->terreno?->metros_por_persona ?? 10);
        $capacidadCalc = $this->geometria->capacidadFromArea($measures['area'] ?? ($data['area'] ?? null), $metros);
        $tipo = $data['tipo_capacidad'] ?? 'calculada';
        $capacidadMax = $tipo === 'manual'
            ? ($data['capacidad_maxima'] ?? $capacidadCalc)
            : ($capacidadCalc ?? ($data['capacidad_maxima'] ?? null));

        $lote = EventoLote::query()->create([
            'evento_terreno_id' => $eventoTerreno->id,
            'evento_zona_id' => null,
            'lote_terreno_id' => $data['lote_terreno_id'] ?? null,
            'codigo' => $data['codigo'],
            'nombre' => $data['nombre'] ?? null,
            'geometria' => $data['geometria'] ?? null,
            'area' => $data['area'] ?? $measures['area'],
            'perimetro' => $data['perimetro'] ?? $measures['perimetro'],
            'capacidad_calculada' => $capacidadCalc,
            'capacidad_maxima' => $capacidadMax,
            'tipo_capacidad' => $tipo,
            'orden' => $data['orden'] ?? (($eventoTerreno->lotesSinZona()->max('orden') ?? 0) + 1),
            'estado' => $data['estado'] ?? EventoLote::ESTADO_DISPONIBLE,
        ]);

        return EventoLote::query()->with(['asignaciones' => fn ($q) => $q->where('estado', 'activa')->with('club')])->findOrFail($lote->id);
    }

    public function updateLote(EventoLote $lote, array $data): EventoLote
    {
        if (array_key_exists('geometria', $data)) {
            $this->geometria->validate($data['geometria']);
            $measures = $this->geometria->measure($data['geometria']);
            $data['area'] = $data['area'] ?? $measures['area'];
            $data['perimetro'] = $data['perimetro'] ?? $measures['perimetro'];
        }

        $area = $data['area'] ?? $lote->area;
        $metros = (float) ($lote->eventoZona?->eventoTerreno?->terreno?->metros_por_persona ?? 10);
        $capacidadCalc = $this->geometria->capacidadFromArea($area !== null ? (float) $area : null, $metros);
        $data['capacidad_calculada'] = $capacidadCalc;

        $tipo = $data['tipo_capacidad'] ?? $lote->tipo_capacidad;
        if ($tipo === 'calculada' && ! array_key_exists('capacidad_maxima', $data)) {
            $data['capacidad_maxima'] = $capacidadCalc;
        }

        $lote->update($data);

        return EventoLote::query()->with(['asignaciones' => fn ($q) => $q->where('estado', 'activa')->with('club')])->findOrFail($lote->id);
    }

    public function deleteLote(EventoLote $lote): void
    {
        if ($lote->asignaciones()->where('estado', 'activa')->exists()) {
            throw ValidationException::withMessages([
                'lote' => ['Libera la asignación activa antes de eliminar el lote.'],
            ]);
        }

        $lote->delete();
    }

    private function cloneFromBase(EventoTerreno $eventoTerreno, Terreno $terreno, ConfiguracionTerreno $config): void
    {
        $terreno->loadMissing(['estructuras']);
        $config->loadMissing(['zonas.lotes', 'lotes']);

        foreach ($terreno->estructuras as $estructura) {
            EventoEstructura::query()->create([
                'evento_terreno_id' => $eventoTerreno->id,
                'estructura_terreno_id' => $estructura->id,
                'nombre' => $estructura->nombre,
                'tipo' => $estructura->tipo,
                'geometria' => $estructura->geometria,
                'area' => $estructura->area,
                'perimetro' => $estructura->perimetro,
                'color' => $estructura->color,
                'orden' => $estructura->orden,
                'estado' => $estructura->estado,
            ]);
        }

        foreach ($config->zonas as $zona) {
            $eventoZona = EventoZona::query()->create([
                'evento_terreno_id' => $eventoTerreno->id,
                'zona_terreno_id' => $zona->id,
                'nombre' => $zona->nombre,
                'geometria' => $zona->geometria,
                'area' => $zona->area,
                'perimetro' => $zona->perimetro,
                'capacidad' => null,
                'color' => $zona->color,
                'orden' => $zona->orden,
                'estado' => $zona->estado,
            ]);

            foreach ($zona->lotes as $lote) {
                EventoLote::query()->create([
                    'evento_terreno_id' => $eventoTerreno->id,
                    'evento_zona_id' => $eventoZona->id,
                    'lote_terreno_id' => $lote->id,
                    'codigo' => $lote->codigo,
                    'nombre' => $lote->nombre,
                    'geometria' => $lote->geometria,
                    'area' => $lote->area,
                    'perimetro' => $lote->perimetro,
                    'capacidad_calculada' => $lote->capacidad_calculada,
                    'capacidad_maxima' => $lote->capacidad_maxima,
                    'tipo_capacidad' => $lote->tipo_capacidad,
                    'orden' => $lote->orden,
                    'estado' => EventoLote::ESTADO_DISPONIBLE,
                ]);
            }
        }

        foreach ($config->lotes->whereNull('zona_terreno_id') as $lote) {
            EventoLote::query()->create([
                'evento_terreno_id' => $eventoTerreno->id,
                'evento_zona_id' => null,
                'lote_terreno_id' => $lote->id,
                'codigo' => $lote->codigo,
                'nombre' => $lote->nombre,
                'geometria' => $lote->geometria,
                'area' => $lote->area,
                'perimetro' => $lote->perimetro,
                'capacidad_calculada' => $lote->capacidad_calculada,
                'capacidad_maxima' => $lote->capacidad_maxima,
                'tipo_capacidad' => $lote->tipo_capacidad,
                'orden' => $lote->orden,
                'estado' => EventoLote::ESTADO_DISPONIBLE,
            ]);
        }
    }

    /**
     * @param  array<string, mixed>|null  $geometria
     */
    private function assertNoEstructuraOverlap(EventoTerreno $eventoTerreno, ?array $geometria): void
    {
        $eventoTerreno->loadMissing('estructuras');
        foreach ($eventoTerreno->estructuras as $estructura) {
            if ($this->geometria->intersects($geometria, $estructura->geometria)) {
                throw ValidationException::withMessages([
                    'geometria' => ["El lote no puede solapar la estructura «{$estructura->nombre}»."],
                ]);
            }
        }
    }
}
