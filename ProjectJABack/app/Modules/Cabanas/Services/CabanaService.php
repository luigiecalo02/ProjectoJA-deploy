<?php

namespace App\Modules\Cabanas\Services;

use App\Models\User;
use App\Modules\Cabanas\Models\Cabana;
use App\Modules\Cabanas\Models\CabanaCama;
use App\Modules\Cabanas\Models\CabanaCuarto;
use App\Modules\Cabanas\Models\CabanaPiso;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class CabanaService
{
    public function list(array $filters, int $perPage = 15): LengthAwarePaginator
    {
        $paginator = Cabana::query()
            ->withCount('pisos')
            ->with(['pisos.cuartos.camas:id,cabana_cuarto_id,capacidad,estado'])
            ->when($filters['q'] ?? null, fn ($q, $value) => $q->where('nombre', 'like', "%{$value}%"))
            ->when($filters['estado'] ?? null, fn ($q, $value) => $q->where('estado', $value))
            ->orderBy('nombre')
            ->paginate(min(max($perPage, 1), 100));

        $paginator->getCollection()->transform(function (Cabana $cabana) {
            $camas = $cabana->pisos->flatMap(fn ($piso) => $piso->cuartos->flatMap->camas);
            $cabana->setAttribute('cuartos_count', $cabana->pisos->sum(fn ($piso) => $piso->cuartos->count()));
            $cabana->setAttribute('camas_count', $camas->count());
            $cabana->setAttribute(
                'capacidad_total',
                $camas->where('estado', '!=', 'no_disponible')->sum('capacidad'),
            );
            $cabana->unsetRelation('pisos');

            return $cabana;
        });

        return $paginator;
    }

    public function find(int $id): Cabana
    {
        return Cabana::query()->with('pisos.cuartos.camas')->findOrFail($id);
    }

    public function create(User $actor, array $data): Cabana
    {
        return Cabana::query()->create($data + ['created_by' => $actor->id]);
    }

    public function update(Cabana $cabana, array $data): Cabana
    {
        $cabana->update($data);

        return $this->find($cabana->id);
    }

    public function delete(Cabana $cabana): void
    {
        if ($cabana->eventos()->exists()) {
            throw ValidationException::withMessages(['cabana' => ['No puede eliminarse porque tiene snapshots de eventos.']]);
        }
        $cabana->delete();
    }

    public function saveCroquis(Cabana $cabana, array $pisos): Cabana
    {
        return DB::transaction(function () use ($cabana, $pisos) {
            $cabana = Cabana::query()->lockForUpdate()->findOrFail($cabana->id);
            $cabana->pisos()->delete();

            foreach ($pisos as $pisoIndex => $pisoData) {
                $piso = CabanaPiso::query()->create([
                    'cabana_id' => $cabana->id,
                    'nombre' => $pisoData['nombre'],
                    'ancho' => $pisoData['ancho'],
                    'alto' => $pisoData['alto'],
                    'orden' => $pisoData['orden'] ?? $pisoIndex,
                ]);
                foreach ($pisoData['cuartos'] ?? [] as $cuartoIndex => $cuartoData) {
                    $camasData = $cuartoData['camas'] ?? [];
                    $this->assertBedsInsideRoom($cuartoData, $camasData);
                    $capacidad = collect($camasData)->sum(fn ($cama) => (int) ($cama['capacidad'] ?? 1));
                    $cuarto = CabanaCuarto::query()->create([
                        'cabana_piso_id' => $piso->id,
                        'nombre' => $cuartoData['nombre'],
                        'codigo' => $cuartoData['codigo'] ?? null,
                        'x' => $cuartoData['x'],
                        'y' => $cuartoData['y'],
                        'ancho' => $cuartoData['ancho'],
                        'alto' => $cuartoData['alto'],
                        'forma' => $cuartoData['forma'] ?? 'rect',
                        'vertices' => $cuartoData['vertices'] ?? null,
                        'puertas' => $cuartoData['puertas'] ?? null,
                        'genero' => $cuartoData['genero'],
                        'capacidad' => max(1, $capacidad ?: (int) ($cuartoData['capacidad'] ?? 1)),
                        'orden' => $cuartoData['orden'] ?? $cuartoIndex,
                    ]);
                    foreach ($camasData as $camaIndex => $camaData) {
                        CabanaCama::query()->create([
                            'cabana_cuarto_id' => $cuarto->id,
                            'codigo' => $camaData['codigo'],
                            'nombre' => $camaData['nombre'] ?? null,
                            'capacidad' => $camaData['capacidad'],
                            'x' => $camaData['x'], 'y' => $camaData['y'],
                            'ancho' => $camaData['ancho'] ?? 36, 'alto' => $camaData['alto'] ?? 26,
                            'rotacion' => $camaData['rotacion'] ?? 0,
                            'estado' => $camaData['estado'] ?? 'disponible',
                            'orden' => $camaData['orden'] ?? $camaIndex,
                        ]);
                    }
                }
            }

            return $this->find($cabana->id);
        });
    }

    /**
     * @param  array<string, mixed>  $cuarto
     * @param  list<array<string, mixed>>  $camas
     */
    private function assertBedsInsideRoom(array $cuarto, array $camas): void
    {
        foreach ($camas as $cama) {
            if (! $this->pointInsideRoom($cuarto, (float) $cama['x'], (float) $cama['y'])) {
                throw ValidationException::withMessages([
                    'pisos' => ['Las camas deben permanecer dentro de su cuarto.'],
                ]);
            }
        }
    }

    /**
     * @param  array<string, mixed>  $cuarto
     */
    private function pointInsideRoom(array $cuarto, float $x, float $y): bool
    {
        $forma = $cuarto['forma'] ?? 'rect';
        $left = (float) $cuarto['x'];
        $top = (float) $cuarto['y'];
        $width = (float) $cuarto['ancho'];
        $height = (float) $cuarto['alto'];

        if ($forma === 'circle') {
            $cx = $left + $width / 2;
            $cy = $top + $height / 2;
            $radius = min($width, $height) / 2;

            return (($x - $cx) ** 2 + ($y - $cy) ** 2) <= $radius ** 2;
        }

        if ($forma === 'polygon' && is_array($cuarto['vertices'] ?? null) && count($cuarto['vertices']) >= 3) {
            return $this->pointInPolygon($x, $y, $cuarto['vertices']);
        }

        return $x >= $left && $y >= $top && $x <= $left + $width && $y <= $top + $height;
    }

    /**
     * @param  list<array{x?: mixed, y?: mixed}>  $vertices
     */
    private function pointInPolygon(float $x, float $y, array $vertices): bool
    {
        $inside = false;
        $count = count($vertices);
        for ($i = 0, $j = $count - 1; $i < $count; $j = $i, $i++) {
            $ax = (float) ($vertices[$i]['x'] ?? 0);
            $ay = (float) ($vertices[$i]['y'] ?? 0);
            $bx = (float) ($vertices[$j]['x'] ?? 0);
            $by = (float) ($vertices[$j]['y'] ?? 0);
            $intersects = ($ay > $y) !== ($by > $y)
                && $x < (($bx - $ax) * ($y - $ay)) / (($by - $ay) ?: PHP_FLOAT_EPSILON) + $ax;
            if ($intersects) {
                $inside = ! $inside;
            }
        }

        return $inside;
    }
}
