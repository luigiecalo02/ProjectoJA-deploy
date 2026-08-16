<?php

namespace App\Modules\Terrains\Services;

use App\Modules\Terrains\Models\EstructuraTerreno;
use App\Modules\Terrains\Models\Terreno;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

final class EstructuraTerrenoService
{
    public function __construct(private readonly GeometriaService $geometria) {}

    public function listByTerreno(Terreno $terreno): Collection
    {
        return $terreno->estructuras()->get();
    }

    public function find(int $id): EstructuraTerreno
    {
        return EstructuraTerreno::query()->with('terreno')->findOrFail($id);
    }

    public function create(Terreno $terreno, array $data): EstructuraTerreno
    {
        $this->geometria->validate($data['geometria'] ?? null, true);
        if (empty($terreno->geometria)) {
            throw ValidationException::withMessages([
                'geometria' => ['Debes dibujar primero el área del terreno.'],
            ]);
        }
        if (! $this->geometria->isContained($data['geometria'] ?? null, $terreno->geometria)) {
            throw ValidationException::withMessages([
                'geometria' => ['La estructura no puede estar fuera del área del terreno.'],
            ]);
        }

        $this->assertNoLoteOverlap($terreno, $data['geometria'] ?? null);

        $measures = $this->geometria->measure($data['geometria'] ?? null);

        $estructura = EstructuraTerreno::query()->create([
            'terreno_id' => $terreno->id,
            'nombre' => $data['nombre'],
            'tipo' => $data['tipo'] ?? EstructuraTerreno::TIPO_GENERAL,
            'descripcion' => $data['descripcion'] ?? null,
            'geometria' => $data['geometria'] ?? null,
            'area' => $data['area'] ?? $measures['area'],
            'perimetro' => $data['perimetro'] ?? $measures['perimetro'],
            'color' => $data['color'] ?? '#6d4c41',
            'orden' => $data['orden'] ?? (($terreno->estructuras()->max('orden') ?? 0) + 1),
            'estado' => $data['estado'] ?? 'activo',
        ]);

        return $this->find($estructura->id);
    }

    public function update(EstructuraTerreno $estructura, array $data): EstructuraTerreno
    {
        if (array_key_exists('geometria', $data)) {
            $this->geometria->validate($data['geometria'], true);
            $terreno = $estructura->terreno;
            if (empty($terreno?->geometria)) {
                throw ValidationException::withMessages([
                    'geometria' => ['Debes dibujar primero el área del terreno.'],
                ]);
            }
            if (! $this->geometria->isContained($data['geometria'], $terreno->geometria)) {
                throw ValidationException::withMessages([
                    'geometria' => ['La estructura no puede estar fuera del área del terreno.'],
                ]);
            }
            $this->assertNoLoteOverlap($terreno, $data['geometria'], $estructura->id);
            $measures = $this->geometria->measure($data['geometria']);
            if (! array_key_exists('area', $data)) {
                $data['area'] = $measures['area'];
            }
            if (! array_key_exists('perimetro', $data)) {
                $data['perimetro'] = $measures['perimetro'];
            }
        }

        $estructura->update($data);

        return $this->find($estructura->id);
    }

    public function delete(EstructuraTerreno $estructura): void
    {
        $estructura->delete();
    }

    /**
     * @param  array<string, mixed>|null  $geometria
     */
    private function assertNoLoteOverlap(Terreno $terreno, ?array $geometria, ?int $ignoreId = null): void
    {
        $terreno->loadMissing(['configuraciones.lotes']);
        foreach ($terreno->configuraciones as $config) {
            foreach ($config->lotes as $lote) {
                if ($this->geometria->intersects($geometria, $lote->geometria)) {
                    throw ValidationException::withMessages([
                        'geometria' => ["La estructura solapa el lote {$lote->codigo}. Muévela o elimina el lote primero."],
                    ]);
                }
            }
        }
        unset($ignoreId);
    }
}
