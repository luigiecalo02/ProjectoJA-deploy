<?php

namespace App\Modules\Lugares\Services;

use App\Modules\Cabanas\Models\Cabana;
use App\Modules\Lugares\Models\Lugar;
use App\Modules\Terrains\Models\Terreno;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class LugarService
{
    public function list(array $filters = [], int $perPage = 15): LengthAwarePaginator
    {
        $query = Lugar::query()->withCount(['terrenos', 'cabanas', 'events']);

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

        return $query->orderBy('nombre')->paginate(min(max($perPage, 1), 200));
    }

    public function find(int $id): Lugar
    {
        return Lugar::query()
            ->with([
                'terrenos:id,lugar_id,nombre',
                'cabanas:id,lugar_id,nombre',
            ])
            ->withCount(['terrenos', 'cabanas', 'events'])
            ->findOrFail($id);
    }

    public function create(array $data): Lugar
    {
        return DB::transaction(function () use ($data) {
            $lugar = Lugar::query()->create([
                'nombre' => $data['nombre'],
                'descripcion' => $data['descripcion'] ?? null,
                'latitud' => $data['latitud'] ?? null,
                'longitud' => $data['longitud'] ?? null,
                'nivel_zoom' => $data['nivel_zoom'] ?? 16,
                'estado' => $data['estado'] ?? Lugar::ESTADO_ACTIVO,
            ]);
            $this->syncCatalogs($lugar, $data);

            return $this->find($lugar->id);
        });
    }

    public function update(Lugar $lugar, array $data): Lugar
    {
        return DB::transaction(function () use ($lugar, $data) {
            $lugar->update(collect($data)->except(['terreno_ids', 'cabana_ids'])->all());
            $this->syncCatalogs($lugar, $data);

            return $this->find($lugar->id);
        });
    }

    /** @return array{terrenos: list<array<string, mixed>>, cabanas: list<array<string, mixed>>} */
    public function catalogos(): array
    {
        return [
            'terrenos' => Terreno::query()
                ->with('lugar:id,nombre')
                ->orderBy('nombre')
                ->get(['id', 'lugar_id', 'nombre'])
                ->map(fn (Terreno $terreno) => $this->catalogItem($terreno->id, $terreno->nombre, $terreno->lugar_id, $terreno->lugar?->nombre))
                ->values()
                ->all(),
            'cabanas' => Cabana::query()
                ->with('lugar:id,nombre')
                ->orderBy('nombre')
                ->get(['id', 'lugar_id', 'nombre'])
                ->map(fn (Cabana $cabana) => $this->catalogItem($cabana->id, $cabana->nombre, $cabana->lugar_id, $cabana->lugar?->nombre))
                ->values()
                ->all(),
        ];
    }

    public function delete(Lugar $lugar): void
    {
        $lugar->loadCount(['terrenos', 'cabanas', 'events']);
        if ($lugar->terrenos_count > 0 || $lugar->cabanas_count > 0 || $lugar->events_count > 0) {
            throw ValidationException::withMessages([
                'lugar' => ['No se puede eliminar un lugar con terrenos, cabañas o eventos asociados.'],
            ]);
        }

        $lugar->delete();
    }

    /** @return array<string, mixed> */
    public function toPayload(Lugar $lugar): array
    {
        return [
            'id' => $lugar->id,
            'nombre' => $lugar->nombre,
            'descripcion' => $lugar->descripcion,
            'latitud' => $lugar->latitud,
            'longitud' => $lugar->longitud,
            'nivel_zoom' => $lugar->nivel_zoom,
            'estado' => $lugar->estado,
            'terrenos_count' => $lugar->terrenos_count ?? null,
            'cabanas_count' => $lugar->cabanas_count ?? null,
            'eventos_count' => $lugar->events_count ?? null,
            'terreno_ids' => $lugar->relationLoaded('terrenos')
                ? $lugar->terrenos->pluck('id')->map(fn ($id) => (int) $id)->values()->all()
                : null,
            'cabana_ids' => $lugar->relationLoaded('cabanas')
                ? $lugar->cabanas->pluck('id')->map(fn ($id) => (int) $id)->values()->all()
                : null,
            'terrenos' => $lugar->relationLoaded('terrenos')
                ? $lugar->terrenos->map(fn (Terreno $terreno) => [
                    'id' => $terreno->id,
                    'nombre' => $terreno->nombre,
                ])->values()->all()
                : null,
            'cabanas' => $lugar->relationLoaded('cabanas')
                ? $lugar->cabanas->map(fn (Cabana $cabana) => [
                    'id' => $cabana->id,
                    'nombre' => $cabana->nombre,
                ])->values()->all()
                : null,
            'created_at' => $lugar->created_at?->toIso8601String(),
            'updated_at' => $lugar->updated_at?->toIso8601String(),
        ];
    }

    /** @param  array<string, mixed>  $data */
    private function syncCatalogs(Lugar $lugar, array $data): void
    {
        if (array_key_exists('terreno_ids', $data)) {
            $ids = $this->normalizeIds($data['terreno_ids'] ?? []);
            Terreno::query()->where('lugar_id', $lugar->id)->whereNotIn('id', $ids ?: [0])->update(['lugar_id' => null]);
            if ($ids !== []) {
                Terreno::query()->whereIn('id', $ids)->update(['lugar_id' => $lugar->id]);
            }
        }

        if (array_key_exists('cabana_ids', $data)) {
            $ids = $this->normalizeIds($data['cabana_ids'] ?? []);
            Cabana::query()->where('lugar_id', $lugar->id)->whereNotIn('id', $ids ?: [0])->update(['lugar_id' => null]);
            if ($ids !== []) {
                Cabana::query()->whereIn('id', $ids)->update(['lugar_id' => $lugar->id]);
            }
        }
    }

    /**
     * @param  mixed  $ids
     * @return list<int>
     */
    private function normalizeIds(mixed $ids): array
    {
        return collect(is_array($ids) ? $ids : [])
            ->map(fn ($id) => (int) $id)
            ->filter(fn (int $id) => $id > 0)
            ->unique()
            ->values()
            ->all();
    }

    /** @return array{id: int, nombre: string, lugar_id: int|null, lugar_nombre: string|null} */
    private function catalogItem(int $id, string $nombre, mixed $lugarId, ?string $lugarNombre): array
    {
        return [
            'id' => $id,
            'nombre' => $nombre,
            'lugar_id' => $lugarId !== null ? (int) $lugarId : null,
            'lugar_nombre' => $lugarNombre,
        ];
    }
}
