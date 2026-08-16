<?php

namespace App\Modules\Terrains\Services;

use App\Modules\Terrains\Models\ConfiguracionTerreno;
use App\Modules\Terrains\Models\LoteTerreno;
use App\Modules\Terrains\Models\Terreno;
use App\Modules\Terrains\Models\ZonaTerreno;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class ConfiguracionTerrenoService
{
    public function listByTerreno(Terreno $terreno): Collection
    {
        return $terreno->configuraciones()
            ->withCount(['zonas', 'lotes'])
            ->orderByDesc('es_default')
            ->orderBy('orden')
            ->orderBy('id')
            ->get();
    }

    public function find(int $id): ConfiguracionTerreno
    {
        return ConfiguracionTerreno::query()
            ->with([
                'terreno.estructuras',
                'zonas.lotes',
                'lotesSinZona',
            ])
            ->withCount(['zonas', 'lotes'])
            ->findOrFail($id);
    }

    public function create(Terreno $terreno, array $data): ConfiguracionTerreno
    {
        $esDefault = (bool) ($data['es_default'] ?? false);

        return DB::transaction(function () use ($terreno, $data, $esDefault) {
            if ($esDefault) {
                $terreno->configuraciones()->update(['es_default' => false]);
            }

            $config = ConfiguracionTerreno::query()->create([
                'terreno_id' => $terreno->id,
                'nombre' => $data['nombre'],
                'descripcion' => $data['descripcion'] ?? null,
                'es_default' => $esDefault || ! $terreno->configuraciones()->exists(),
                'orden' => $data['orden'] ?? (($terreno->configuraciones()->max('orden') ?? 0) + 1),
                'estado' => $data['estado'] ?? 'activo',
            ]);

            return $this->find($config->id);
        });
    }

    public function update(ConfiguracionTerreno $config, array $data): ConfiguracionTerreno
    {
        return DB::transaction(function () use ($config, $data) {
            if (! empty($data['es_default'])) {
                ConfiguracionTerreno::query()
                    ->where('terreno_id', $config->terreno_id)
                    ->where('id', '!=', $config->id)
                    ->update(['es_default' => false]);
                $data['es_default'] = true;
            }

            $config->update($data);

            return $this->find($config->id);
        });
    }

    public function delete(ConfiguracionTerreno $config): void
    {
        $count = ConfiguracionTerreno::query()->where('terreno_id', $config->terreno_id)->count();
        if ($count <= 1) {
            throw ValidationException::withMessages([
                'configuracion' => ['Debe existir al menos una configuración por terreno.'],
            ]);
        }

        DB::transaction(function () use ($config) {
            $wasDefault = $config->es_default;
            $terrenoId = $config->terreno_id;
            $config->zonas()->each(function (ZonaTerreno $zona) {
                $zona->lotes()->delete();
                $zona->delete();
            });
            $config->lotes()->whereNull('zona_terreno_id')->delete();
            $config->delete();

            if ($wasDefault) {
                $next = ConfiguracionTerreno::query()->where('terreno_id', $terrenoId)->orderBy('id')->first();
                $next?->update(['es_default' => true]);
            }
        });
    }

    public function duplicate(ConfiguracionTerreno $source, ?string $nombre = null): ConfiguracionTerreno
    {
        $source->loadMissing(['zonas.lotes', 'lotesSinZona', 'terreno']);

        return DB::transaction(function () use ($source, $nombre) {
            $copy = ConfiguracionTerreno::query()->create([
                'terreno_id' => $source->terreno_id,
                'nombre' => $nombre ?: ($source->nombre.' (copia)'),
                'descripcion' => $source->descripcion,
                'es_default' => false,
                'orden' => (($source->terreno->configuraciones()->max('orden') ?? 0) + 1),
                'estado' => $source->estado,
            ]);

            $zonaMap = [];
            foreach ($source->zonas as $zona) {
                $nueva = ZonaTerreno::query()->create([
                    'configuracion_terreno_id' => $copy->id,
                    'nombre' => $zona->nombre,
                    'descripcion' => $zona->descripcion,
                    'geometria' => $zona->geometria,
                    'area' => $zona->area,
                    'perimetro' => $zona->perimetro,
                    'color' => $zona->color,
                    'orden' => $zona->orden,
                    'estado' => $zona->estado,
                ]);
                $zonaMap[$zona->id] = $nueva->id;

                foreach ($zona->lotes as $lote) {
                    LoteTerreno::query()->create([
                        'configuracion_terreno_id' => $copy->id,
                        'zona_terreno_id' => $nueva->id,
                        'codigo' => $lote->codigo,
                        'nombre' => $lote->nombre,
                        'descripcion' => $lote->descripcion,
                        'geometria' => $lote->geometria,
                        'area' => $lote->area,
                        'perimetro' => $lote->perimetro,
                        'capacidad_calculada' => $lote->capacidad_calculada,
                        'capacidad_maxima' => $lote->capacidad_maxima,
                        'tipo_capacidad' => $lote->tipo_capacidad,
                        'orden' => $lote->orden,
                        'estado' => $lote->estado,
                    ]);
                }
            }

            foreach ($source->lotes->whereNull('zona_terreno_id') as $lote) {
                LoteTerreno::query()->create([
                    'configuracion_terreno_id' => $copy->id,
                    'zona_terreno_id' => null,
                    'codigo' => $lote->codigo,
                    'nombre' => $lote->nombre,
                    'descripcion' => $lote->descripcion,
                    'geometria' => $lote->geometria,
                    'area' => $lote->area,
                    'perimetro' => $lote->perimetro,
                    'capacidad_calculada' => $lote->capacidad_calculada,
                    'capacidad_maxima' => $lote->capacidad_maxima,
                    'tipo_capacidad' => $lote->tipo_capacidad,
                    'orden' => $lote->orden,
                    'estado' => $lote->estado,
                ]);
            }

            return $this->find($copy->id);
        });
    }
}
