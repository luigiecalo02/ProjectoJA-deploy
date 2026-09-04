<?php

namespace App\Modules\Events\Services;

use App\Modules\Events\Models\CategoriaSubevento;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

final class CategoriaSubeventoService
{
    /**
     * @return list<CategoriaSubevento>
     */
    public function list(bool $incluirInactivas = false): array
    {
        $query = CategoriaSubevento::query()->orderBy('orden')->orderBy('nombre');

        if (! $incluirInactivas) {
            $query->where('estado', true);
        }

        return $query->get()->all();
    }

    public function find(int $id): CategoriaSubevento
    {
        return CategoriaSubevento::query()->findOrFail($id);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function create(array $data): CategoriaSubevento
    {
        $slug = $this->resolveSlug((string) ($data['slug'] ?? ''), (string) $data['nombre']);

        return CategoriaSubevento::query()->create([
            'nombre' => trim((string) $data['nombre']),
            'slug' => $slug,
            'color' => $data['color'] ?? null,
            'icono' => $data['icono'] ?? null,
            'orden' => (int) ($data['orden'] ?? 0),
            'estado' => array_key_exists('estado', $data) ? (bool) $data['estado'] : true,
            'es_sistema' => false,
            'maneja_puntos' => array_key_exists('maneja_puntos', $data) ? (bool) $data['maneja_puntos'] : true,
            'maneja_fecha_inicio' => array_key_exists('maneja_fecha_inicio', $data) ? (bool) $data['maneja_fecha_inicio'] : false,
            'maneja_fecha_fin' => array_key_exists('maneja_fecha_fin', $data) ? (bool) $data['maneja_fecha_fin'] : false,
        ]);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function update(CategoriaSubevento $categoria, array $data): CategoriaSubevento
    {
        if (isset($data['nombre'])) {
            $categoria->nombre = trim((string) $data['nombre']);
        }

        if (array_key_exists('slug', $data) || isset($data['nombre'])) {
            $slugSource = array_key_exists('slug', $data) && filled($data['slug'])
                ? (string) $data['slug']
                : (string) $categoria->nombre;
            $categoria->slug = $this->resolveSlug($slugSource, (string) $categoria->nombre, $categoria->id);
        }

        foreach (['color', 'icono'] as $field) {
            if (array_key_exists($field, $data)) {
                $categoria->{$field} = $data[$field];
            }
        }

        if (array_key_exists('orden', $data)) {
            $categoria->orden = (int) $data['orden'];
        }

        foreach (['estado', 'maneja_puntos', 'maneja_fecha_inicio', 'maneja_fecha_fin'] as $flag) {
            if (array_key_exists($flag, $data)) {
                $categoria->{$flag} = (bool) $data[$flag];
            }
        }

        $categoria->save();

        return $categoria->refresh();
    }

    public function delete(CategoriaSubevento $categoria): void
    {
        if ($categoria->es_sistema) {
            throw ValidationException::withMessages([
                'categoria' => ['No se puede eliminar una categoría creada por el sistema.'],
            ]);
        }

        if ($categoria->events()->exists()) {
            $categoria->estado = false;
            $categoria->save();

            return;
        }

        $categoria->delete();
    }

    private function resolveSlug(string $slug, string $nombre, ?int $ignoreId = null): string
    {
        $base = Str::slug($slug !== '' ? $slug : $nombre);
        if ($base === '') {
            $base = 'categoria';
        }

        $candidate = $base;
        $suffix = 2;
        while (
            CategoriaSubevento::query()
                ->where('slug', $candidate)
                ->when($ignoreId, fn ($q) => $q->where('id', '!=', $ignoreId))
                ->exists()
        ) {
            $candidate = $base.'-'.$suffix;
            $suffix++;
        }

        if ($candidate === '') {
            throw ValidationException::withMessages([
                'slug' => ['No se pudo generar un slug válido.'],
            ]);
        }

        return $candidate;
    }
}
