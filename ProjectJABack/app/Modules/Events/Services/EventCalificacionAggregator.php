<?php

namespace App\Modules\Events\Services;

use App\Modules\Events\Models\EventoCalificacion;
use Illuminate\Support\Collection;

/**
 * Agrega calificaciones de varios jueces (promedio + aportes anónimos).
 */
final class EventCalificacionAggregator
{
    /**
     * @param  Collection<int, EventoCalificacion>  $rows
     * @return array<string, mixed>|null
     */
    public function averagePayload(Collection $rows): ?array
    {
        $judgeRows = $rows
            ->filter(fn (EventoCalificacion $c) => $c->calificado_por !== null)
            ->sortBy('id')
            ->values();

        if ($judgeRows->isEmpty()) {
            $legacy = $rows->sortByDesc('id')->first();

            return $legacy ? $this->singlePayload($legacy, false) : null;
        }

        if ($judgeRows->count() === 1) {
            return $this->singlePayload($judgeRows->first(), false);
        }

        $avg = round((float) $judgeRows->avg(fn (EventoCalificacion $c) => (float) $c->puntaje_obtenido), 2);
        $aportes = [];
        foreach ($judgeRows as $index => $cal) {
            $aportes[] = [
                'etiqueta' => 'Juez '.($index + 1),
                'puntaje_obtenido' => round((float) $cal->puntaje_obtenido, 2),
                'observaciones' => $cal->observaciones,
                'updated_at' => $cal->updated_at?->toIso8601String(),
            ];
        }

        $latest = $judgeRows->sortByDesc(fn (EventoCalificacion $c) => $c->updated_at?->timestamp ?? 0)->first();

        return [
            'id' => null,
            'puntaje_obtenido' => $avg,
            'observaciones' => 'Promedio de '.$judgeRows->count().' jueces',
            'calificado_por' => null,
            'updated_at' => $latest?->updated_at?->toIso8601String(),
            'es_agregado' => false,
            'es_promedio' => true,
            'jueces_count' => $judgeRows->count(),
            'aportes' => $aportes,
            'observaciones_director' => null,
            'observaciones_director_updated_at' => null,
            'detalles' => $this->averageDetalles($judgeRows),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function singlePayload(EventoCalificacion $cal, bool $anonymous = true): array
    {
        $aportes = [];
        if ($anonymous && $cal->calificado_por !== null) {
            $aportes[] = [
                'etiqueta' => 'Juez 1',
                'puntaje_obtenido' => round((float) $cal->puntaje_obtenido, 2),
                'observaciones' => $cal->observaciones,
                'updated_at' => $cal->updated_at?->toIso8601String(),
            ];
        }

        return [
            'id' => $cal->id,
            'puntaje_obtenido' => round((float) $cal->puntaje_obtenido, 2),
            'observaciones' => $cal->observaciones,
            'calificado_por' => null,
            'updated_at' => $cal->updated_at?->toIso8601String(),
            'es_agregado' => false,
            'es_promedio' => false,
            'jueces_count' => $cal->calificado_por !== null ? 1 : 0,
            'aportes' => $aportes,
            'observaciones_director' => null,
            'observaciones_director_updated_at' => null,
            'detalles' => ($cal->relationLoaded('detalles') ? $cal->detalles : $cal->detalles()->get())
                ->map(fn ($d) => [
                    'criterio_evaluacion_id' => (int) $d->criterio_evaluacion_id,
                    'puntos' => (float) $d->puntos,
                ])->values()->all(),
        ];
    }

    /**
     * Promedio de puntaje por (organizacion, evento) y luego suma por organización.
     *
     * @param  list<int>  $leafIds
     * @param  list<int>  $orgIds
     * @return array<int, float>
     */
    public function averagedTotalsByOrg(array $leafIds, array $orgIds): array
    {
        if ($leafIds === [] || $orgIds === []) {
            return [];
        }

        $rows = EventoCalificacion::query()
            ->whereIn('evento_id', $leafIds)
            ->whereIn('organizacion_id', $orgIds)
            ->whereNull('persona_id')
            ->get(['organizacion_id', 'evento_id', 'puntaje_obtenido', 'calificado_por']);

        /** @var array<int, array<int, list<float>>> $bucket */
        $bucket = [];
        foreach ($rows as $row) {
            $orgId = (int) $row->organizacion_id;
            $eventoId = (int) $row->evento_id;
            $bucket[$orgId][$eventoId][] = (float) $row->puntaje_obtenido;
        }

        $out = [];
        foreach ($bucket as $orgId => $byEvent) {
            $sum = 0.0;
            foreach ($byEvent as $scores) {
                if ($scores === []) {
                    continue;
                }
                $sum += array_sum($scores) / count($scores);
            }
            $out[$orgId] = round($sum, 2);
        }

        return $out;
    }

    /**
     * Promedio por (organización, evento).
     *
     * @param  list<int>  $eventoIds
     * @param  list<int>  $orgIds
     * @return array<int, array<int, float>> orgId => [eventoId => promedio]
     */
    public function averagedScoresMatrix(array $eventoIds, array $orgIds): array
    {
        if ($eventoIds === [] || $orgIds === []) {
            return [];
        }

        $rows = EventoCalificacion::query()
            ->whereIn('evento_id', $eventoIds)
            ->whereIn('organizacion_id', $orgIds)
            ->whereNull('persona_id')
            ->get(['organizacion_id', 'evento_id', 'puntaje_obtenido', 'calificado_por']);

        /** @var array<int, array<int, list<float>>> $bucket */
        $bucket = [];
        foreach ($rows as $row) {
            $orgId = (int) $row->organizacion_id;
            $eventoId = (int) $row->evento_id;
            $bucket[$orgId][$eventoId][] = (float) $row->puntaje_obtenido;
        }

        $out = [];
        foreach ($bucket as $orgId => $byEvent) {
            foreach ($byEvent as $eventoId => $scores) {
                if ($scores === []) {
                    continue;
                }
                $out[$orgId][$eventoId] = round(array_sum($scores) / count($scores), 2);
            }
        }

        return $out;
    }

    /**
     * @param  Collection<int, EventoCalificacion>  $judgeRows
     * @return list<array{criterio_evaluacion_id: int, puntos: float}>
     */
    private function averageDetalles(Collection $judgeRows): array
    {
        $sums = [];
        $counts = [];

        foreach ($judgeRows as $cal) {
            $detalles = $cal->relationLoaded('detalles') ? $cal->detalles : $cal->detalles()->get();
            foreach ($detalles as $d) {
                $cid = (int) $d->criterio_evaluacion_id;
                $sums[$cid] = ($sums[$cid] ?? 0) + (float) $d->puntos;
                $counts[$cid] = ($counts[$cid] ?? 0) + 1;
            }
        }

        $out = [];
        foreach ($sums as $cid => $sum) {
            $out[] = [
                'criterio_evaluacion_id' => (int) $cid,
                'puntos' => round($sum / max(1, (int) $counts[$cid]), 2),
            ];
        }

        return $out;
    }
}
