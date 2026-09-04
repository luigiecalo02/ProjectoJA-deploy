<?php

namespace App\Modules\Events\Services;

use App\Modules\Events\Models\CriterioEvaluacion;
use App\Modules\Events\Models\Event;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class CriterioEvaluacionService
{
    /**
     * @return list<CriterioEvaluacion>
     */
    public function list(bool $incluirInactivos = false): array
    {
        $query = CriterioEvaluacion::query()->orderBy('orden')->orderBy('nombre');

        if (! $incluirInactivos) {
            $query->where('estado', true);
        }

        return $query->get()->all();
    }

    public function find(int $id): CriterioEvaluacion
    {
        return CriterioEvaluacion::query()->findOrFail($id);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function create(array $data): CriterioEvaluacion
    {
        return CriterioEvaluacion::query()->create([
            'nombre' => trim((string) $data['nombre']),
            'descripcion' => isset($data['descripcion']) ? trim((string) $data['descripcion']) ?: null : null,
            'color' => isset($data['color']) ? trim((string) $data['color']) ?: null : null,
            'icono' => isset($data['icono']) ? trim((string) $data['icono']) ?: null : null,
            'estado' => array_key_exists('estado', $data) ? (bool) $data['estado'] : true,
            'es_sistema' => false,
            'orden' => (int) ($data['orden'] ?? 0),
        ]);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function update(CriterioEvaluacion $criterio, array $data): CriterioEvaluacion
    {
        if (isset($data['nombre'])) {
            $criterio->nombre = trim((string) $data['nombre']);
        }

        if (array_key_exists('descripcion', $data)) {
            $criterio->descripcion = $data['descripcion'] !== null
                ? (trim((string) $data['descripcion']) ?: null)
                : null;
        }

        if (array_key_exists('color', $data)) {
            $criterio->color = $data['color'] !== null ? (trim((string) $data['color']) ?: null) : null;
        }

        if (array_key_exists('icono', $data)) {
            $criterio->icono = $data['icono'] !== null ? (trim((string) $data['icono']) ?: null) : null;
        }

        if (array_key_exists('estado', $data)) {
            $criterio->estado = (bool) $data['estado'];
        }

        if (array_key_exists('orden', $data)) {
            $criterio->orden = (int) $data['orden'];
        }

        $criterio->save();

        return $criterio->refresh();
    }

    public function delete(CriterioEvaluacion $criterio): void
    {
        if ($criterio->es_sistema) {
            throw ValidationException::withMessages([
                'criterio' => ['No se puede eliminar un criterio creado por el sistema.'],
            ]);
        }

        if ($criterio->eventos()->exists()) {
            $criterio->estado = false;
            $criterio->save();

            return;
        }

        $criterio->delete();
    }

    /**
     * Sync criterios assigned to a subevent. Empty list clears assignment (generic scoring).
     *
     * @param  list<array{id?: int, criterio_evaluacion_id?: int, puntos: float|int|string, orden?: int}>  $items
     */
    public function syncForEvent(Event $event, array $items): void
    {
        $normalized = [];
        foreach ($items as $index => $item) {
            $criterioId = (int) ($item['criterio_evaluacion_id'] ?? $item['id'] ?? 0);
            if ($criterioId <= 0) {
                continue;
            }
            $normalized[] = [
                'criterio_evaluacion_id' => $criterioId,
                'puntos' => round((float) ($item['puntos'] ?? 0), 2),
                'orden' => (int) ($item['orden'] ?? $index),
            ];
        }

        if ($normalized !== []) {
            $ids = array_column($normalized, 'criterio_evaluacion_id');
            if (count($ids) !== count(array_unique($ids))) {
                throw ValidationException::withMessages([
                    'criterios' => ['No puedes asignar el mismo criterio más de una vez.'],
                ]);
            }

            $existing = CriterioEvaluacion::query()
                ->whereIn('id', $ids)
                ->where('estado', true)
                ->pluck('id')
                ->map(fn ($id) => (int) $id)
                ->all();

            if (count($existing) !== count($ids)) {
                throw ValidationException::withMessages([
                    'criterios' => ['Uno o más criterios no existen o están inactivos.'],
                ]);
            }

            $suma = array_sum(array_column($normalized, 'puntos'));
            $maximo = $event->puntaje_maximo !== null ? (float) $event->puntaje_maximo : null;

            if ($maximo === null) {
                throw ValidationException::withMessages([
                    'criterios' => ['Define el puntaje máximo del subevento antes de asignar criterios.'],
                ]);
            }

            if (abs($suma - $maximo) > 0.009) {
                throw ValidationException::withMessages([
                    'criterios' => [
                        "La suma de puntos de criterios ({$suma}) debe ser igual al puntaje máximo ({$maximo}).",
                    ],
                ]);
            }
        }

        DB::transaction(function () use ($event, $normalized) {
            $sync = [];
            foreach ($normalized as $row) {
                $sync[$row['criterio_evaluacion_id']] = [
                    'puntos' => $row['puntos'],
                    'orden' => $row['orden'],
                ];
            }
            $event->criterios()->sync($sync);
        });
    }
}
