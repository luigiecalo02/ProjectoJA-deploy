<?php

namespace App\Modules\Terrains\Services;

use App\Modules\Terrains\Models\ConfiguracionTerreno;
use App\Modules\Terrains\Models\ZonaTerreno;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

final class ZonaTerrenoService
{
    public function __construct(private readonly GeometriaService $geometria) {}

    public function listByConfig(ConfiguracionTerreno $config): Collection
    {
        return $config->zonas()->with('lotes')->get();
    }

    public function find(int $id): ZonaTerreno
    {
        return ZonaTerreno::query()->with(['configuracion.terreno', 'lotes'])->findOrFail($id);
    }

    public function create(ConfiguracionTerreno $config, array $data): ZonaTerreno
    {
        $config->loadMissing('terreno');
        $terreno = $config->terreno;
        $this->geometria->validate($data['geometria'] ?? null, true);
        if (empty($terreno?->geometria)) {
            throw ValidationException::withMessages([
                'geometria' => ['Debes dibujar primero el área del terreno.'],
            ]);
        }
        if (! $this->geometria->isContained($data['geometria'] ?? null, $terreno->geometria)) {
            throw ValidationException::withMessages([
                'geometria' => ['La zona no puede estar fuera del área del terreno.'],
            ]);
        }

        $measures = $this->geometria->measure($data['geometria'] ?? null);

        $zona = ZonaTerreno::query()->create([
            'configuracion_terreno_id' => $config->id,
            'nombre' => $data['nombre'],
            'descripcion' => $data['descripcion'] ?? null,
            'geometria' => $data['geometria'] ?? null,
            'area' => $data['area'] ?? $measures['area'],
            'perimetro' => $data['perimetro'] ?? $measures['perimetro'],
            'color' => $data['color'] ?? null,
            'orden' => $data['orden'] ?? (($config->zonas()->max('orden') ?? 0) + 1),
            'estado' => $data['estado'] ?? 'activo',
        ]);

        return $this->find($zona->id);
    }

    public function update(ZonaTerreno $zona, array $data): ZonaTerreno
    {
        if (array_key_exists('geometria', $data)) {
            $this->geometria->validate($data['geometria'], true);
            $zona->loadMissing('configuracion.terreno');
            $terreno = $zona->configuracion?->terreno;
            if (empty($terreno?->geometria)) {
                throw ValidationException::withMessages([
                    'geometria' => ['Debes dibujar primero el área del terreno.'],
                ]);
            }
            if (! $this->geometria->isContained($data['geometria'], $terreno->geometria)) {
                throw ValidationException::withMessages([
                    'geometria' => ['La zona no puede estar fuera del área del terreno.'],
                ]);
            }
            $measures = $this->geometria->measure($data['geometria']);
            if (! array_key_exists('area', $data)) {
                $data['area'] = $measures['area'];
            }
            if (! array_key_exists('perimetro', $data)) {
                $data['perimetro'] = $measures['perimetro'];
            }
        }

        $zona->update($data);

        return $this->find($zona->id);
    }

    public function delete(ZonaTerreno $zona): void
    {
        if ($zona->lotes()->exists()) {
            $zona->lotes()->delete();
        }
        $zona->delete();
    }
}
