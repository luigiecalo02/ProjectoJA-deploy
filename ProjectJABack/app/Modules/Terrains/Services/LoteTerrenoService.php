<?php

namespace App\Modules\Terrains\Services;

use App\Modules\Terrains\Models\ConfiguracionTerreno;
use App\Modules\Terrains\Models\LoteTerreno;
use App\Modules\Terrains\Models\Terreno;
use App\Modules\Terrains\Models\ZonaTerreno;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

final class LoteTerrenoService
{
    public function __construct(private readonly GeometriaService $geometria) {}

    public function listByZona(ZonaTerreno $zona): Collection
    {
        return $zona->lotes()->get();
    }

    public function listByConfig(ConfiguracionTerreno $config): Collection
    {
        return $config->lotes()->get();
    }

    public function find(int $id): LoteTerreno
    {
        return LoteTerreno::query()->with(['zona', 'configuracion.terreno'])->findOrFail($id);
    }

    public function createOnConfig(ConfiguracionTerreno $config, array $data, ?ZonaTerreno $zona = null): LoteTerreno
    {
        $config->loadMissing('terreno.estructuras');
        $terreno = $config->terreno;
        if (! $terreno) {
            throw ValidationException::withMessages([
                'configuracion' => ['La configuración no tiene terreno asociado.'],
            ]);
        }

        if ($zona && (int) $zona->configuracion_terreno_id !== (int) $config->id) {
            throw ValidationException::withMessages([
                'zona_terreno_id' => ['La zona no pertenece a esta configuración.'],
            ]);
        }

        $this->geometria->validate($data['geometria'] ?? null, true);
        $this->assertContained($data['geometria'] ?? null, $terreno, $zona);
        $this->assertNoEstructuraOverlap($terreno, $data['geometria'] ?? null);

        $measures = $this->geometria->measure($data['geometria'] ?? null);
        $metros = (float) ($terreno->metros_por_persona ?? 10);
        $capacidadCalc = $this->geometria->capacidadFromArea($measures['area'] ?? ($data['area'] ?? null), $metros);
        $tipo = $data['tipo_capacidad'] ?? LoteTerreno::TIPO_CALCULADA;
        $capacidadMax = $tipo === LoteTerreno::TIPO_MANUAL
            ? ($data['capacidad_maxima'] ?? $capacidadCalc)
            : ($capacidadCalc ?? ($data['capacidad_maxima'] ?? null));

        $ordenBase = $zona
            ? ($zona->lotes()->max('orden') ?? 0)
            : ($config->lotes()->whereNull('zona_terreno_id')->max('orden') ?? 0);

        $lote = LoteTerreno::query()->create([
            'configuracion_terreno_id' => $config->id,
            'zona_terreno_id' => $zona?->id,
            'codigo' => $data['codigo'],
            'nombre' => $data['nombre'] ?? null,
            'descripcion' => $data['descripcion'] ?? null,
            'geometria' => $data['geometria'] ?? null,
            'area' => $data['area'] ?? $measures['area'],
            'perimetro' => $data['perimetro'] ?? $measures['perimetro'],
            'capacidad_calculada' => $capacidadCalc,
            'capacidad_maxima' => $capacidadMax,
            'tipo_capacidad' => $tipo,
            'orden' => $data['orden'] ?? ($ordenBase + 1),
            'estado' => $data['estado'] ?? 'disponible',
        ]);

        return $this->find($lote->id);
    }

    public function create(ZonaTerreno $zona, array $data): LoteTerreno
    {
        $zona->loadMissing('configuracion');
        $config = $zona->configuracion;
        if (! $config) {
            throw ValidationException::withMessages([
                'zona' => ['La zona no tiene configuración asociada.'],
            ]);
        }

        return $this->createOnConfig($config, $data, $zona);
    }

    public function update(LoteTerreno $lote, array $data): LoteTerreno
    {
        $lote->loadMissing(['zona', 'configuracion.terreno']);
        $terreno = $lote->configuracion?->terreno;
        $zona = $lote->zona;

        if (array_key_exists('geometria', $data)) {
            $this->geometria->validate($data['geometria'], true);
            if (! $terreno) {
                throw ValidationException::withMessages([
                    'geometria' => ['El lote no tiene terreno asociado.'],
                ]);
            }
            $this->assertContained($data['geometria'], $terreno, $zona);
            $this->assertNoEstructuraOverlap($terreno, $data['geometria']);
            $measures = $this->geometria->measure($data['geometria']);
            if (! array_key_exists('area', $data)) {
                $data['area'] = $measures['area'];
            }
            if (! array_key_exists('perimetro', $data)) {
                $data['perimetro'] = $measures['perimetro'];
            }
        }

        $area = $data['area'] ?? $lote->area;
        $metros = (float) ($terreno?->metros_por_persona ?? 10);
        $capacidadCalc = $this->geometria->capacidadFromArea($area !== null ? (float) $area : null, $metros);
        $data['capacidad_calculada'] = $capacidadCalc;

        $tipo = $data['tipo_capacidad'] ?? $lote->tipo_capacidad;
        if ($tipo === LoteTerreno::TIPO_CALCULADA && ! array_key_exists('capacidad_maxima', $data)) {
            $data['capacidad_maxima'] = $capacidadCalc;
        }

        $lote->update($data);

        return $this->find($lote->id);
    }

    public function delete(LoteTerreno $lote): void
    {
        if ($lote->eventosLotes()->whereHas('asignaciones', fn ($q) => $q->where('estado', 'activa'))->exists()) {
            throw ValidationException::withMessages([
                'lote' => ['No se puede eliminar un lote con asignaciones activas en eventos.'],
            ]);
        }

        $lote->delete();
    }

    /**
     * @param  array<string, mixed>|null  $geometria
     */
    private function assertContained(?array $geometria, Terreno $terreno, ?ZonaTerreno $zona): void
    {
        if ($zona) {
            if (empty($zona->geometria)) {
                throw ValidationException::withMessages([
                    'geometria' => ['Debes dibujar primero el área de la zona.'],
                ]);
            }
            if (! $this->geometria->isContained($geometria, $zona->geometria)) {
                throw ValidationException::withMessages([
                    'geometria' => ['El lote no puede estar fuera del área de la zona.'],
                ]);
            }

            return;
        }

        if (empty($terreno->geometria)) {
            throw ValidationException::withMessages([
                'geometria' => ['Debes dibujar primero el área del terreno.'],
            ]);
        }
        if (! $this->geometria->isContained($geometria, $terreno->geometria)) {
            throw ValidationException::withMessages([
                'geometria' => ['El lote no puede estar fuera del área del terreno.'],
            ]);
        }
    }

    /**
     * @param  array<string, mixed>|null  $geometria
     */
    private function assertNoEstructuraOverlap(Terreno $terreno, ?array $geometria): void
    {
        $terreno->loadMissing('estructuras');
        foreach ($terreno->estructuras as $estructura) {
            if ($this->geometria->intersects($geometria, $estructura->geometria)) {
                throw ValidationException::withMessages([
                    'geometria' => ["El lote no puede solapar la estructura «{$estructura->nombre}»."],
                ]);
            }
        }
    }
}
