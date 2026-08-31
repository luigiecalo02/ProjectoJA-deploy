<?php

namespace App\Modules\Terrains\Services;

use App\Models\User;
use App\Modules\Shared\Models\StoredFile;
use App\Modules\Shared\Services\ImageOptimizer;
use App\Modules\Terrains\Models\ConfiguracionTerreno;
use App\Modules\Terrains\Models\Terreno;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class TerrenoService
{
    public function __construct(
        private readonly GeometriaService $geometria,
        private readonly ImageOptimizer $imageOptimizer,
    ) {}

    public function list(array $filters = [], int $perPage = 15): LengthAwarePaginator
    {
        $query = Terreno::query()
            ->with('lugar:id,nombre,latitud,longitud,nivel_zoom,estado')
            ->withCount(['configuraciones', 'estructuras', 'eventosTerrenos']);

        if (! empty($filters['q'])) {
            $q = trim((string) $filters['q']);
            $query->where(function ($inner) use ($q) {
                $inner->where('nombre', 'like', "%{$q}%")
                    ->orWhere('descripcion', 'like', "%{$q}%");
            });
        }

        if (! empty($filters['estado'])) {
            $query->where('estado', $filters['estado']);
        }

        if (! empty($filters['lugar_id'])) {
            $query->where('lugar_id', (int) $filters['lugar_id']);
        }

        return $query->orderByDesc('id')->paginate($perPage);
    }

    public function find(int $id): Terreno
    {
        return Terreno::query()
            ->with([
                'lugar:id,nombre,latitud,longitud,nivel_zoom,estado',
                'estructuras',
                'configuraciones' => fn ($q) => $q->withCount(['zonas', 'lotes'])->orderByDesc('es_default')->orderBy('orden'),
                'creador:id,name,email',
            ])
            ->withCount(['configuraciones', 'estructuras', 'eventosTerrenos'])
            ->findOrFail($id);
    }

    public function create(User $actor, array $data): Terreno
    {
        $this->geometria->validate($data['geometria'] ?? null);
        $measures = $this->geometria->measure($data['geometria'] ?? null);

        return DB::transaction(function () use ($actor, $data, $measures) {
            $terreno = Terreno::query()->create([
                'lugar_id' => $data['lugar_id'],
                'nombre' => $data['nombre'],
                'descripcion' => $data['descripcion'] ?? null,
                'latitud' => $data['latitud'] ?? null,
                'longitud' => $data['longitud'] ?? null,
                'nivel_zoom' => $data['nivel_zoom'] ?? 16,
                'geometria' => $data['geometria'] ?? null,
                'area_total' => $data['area_total'] ?? $measures['area'],
                'perimetro' => $data['perimetro'] ?? $measures['perimetro'],
                'metros_por_persona' => $data['metros_por_persona'] ?? 10,
                'imagen_referencia' => $data['imagen_referencia'] ?? null,
                'estado' => $data['estado'] ?? Terreno::ESTADO_ACTIVO,
                'created_by' => $actor->id,
            ]);

            ConfiguracionTerreno::query()->create([
                'terreno_id' => $terreno->id,
                'nombre' => 'Configuración base',
                'descripcion' => 'Distribución inicial de zonas y lotes',
                'es_default' => true,
                'orden' => 1,
                'estado' => 'activo',
            ]);

            return $this->find($terreno->id);
        });
    }

    public function update(Terreno $terreno, array $data): Terreno
    {
        if (array_key_exists('geometria', $data)) {
            $this->geometria->validate($data['geometria']);
            $measures = $this->geometria->measure($data['geometria']);
            if (! array_key_exists('area_total', $data)) {
                $data['area_total'] = $measures['area'];
            }
            if (! array_key_exists('perimetro', $data)) {
                $data['perimetro'] = $measures['perimetro'];
            }
        }

        $terreno->update($data);

        return $this->find($terreno->id);
    }

    public function delete(Terreno $terreno): void
    {
        if ($terreno->eventosTerrenos()->exists()) {
            throw ValidationException::withMessages([
                'terreno' => ['No se puede eliminar un terreno asociado a eventos. Desasócialo primero.'],
            ]);
        }

        DB::transaction(function () use ($terreno) {
            $terreno->estructuras()->delete();
            $terreno->configuraciones()->each(function (ConfiguracionTerreno $config) {
                $config->zonas()->each(function ($zona) {
                    $zona->lotes()->delete();
                    $zona->delete();
                });
                $config->lotes()->whereNull('zona_terreno_id')->delete();
                $config->delete();
            });
            $terreno->delete();
        });
    }

    public function storeImagen(Terreno $terreno, UploadedFile $file, User $actor): Terreno
    {
        $stored = $this->imageOptimizer->store($file, "terrenos/{$terreno->id}", 'ter');
        $url = url('storage/'.$stored->path);

        StoredFile::query()->create([
            'name' => $file->getClientOriginalName(),
            'path' => $stored->path,
            'size' => $stored->size,
            'mime_type' => $stored->mime,
            'hash' => $stored->hash,
            'uploaded_by' => $actor->id,
        ]);

        $terreno->update(['imagen_referencia' => $url]);

        return $this->find($terreno->id);
    }
}
