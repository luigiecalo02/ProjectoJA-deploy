<?php

namespace App\Modules\Settings\Services;

use App\Models\User;
use App\Modules\Clubs\Models\Persona;
use App\Modules\Events\Models\Event;
use App\Modules\Events\Models\EventoAsistencia;
use App\Modules\Events\Models\TipoEvento;
use App\Modules\Organizations\Models\PersonaOrganizacion;
use App\Modules\Settings\Models\AppSetting;
use App\Modules\Shared\Services\PublicFileService;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\Response;

final class ClubesAttendanceService
{
    public function __construct(private readonly PublicFileService $publicFiles) {}

    /**
     * @return list<array<string, mixed>>
     */
    public function listEvents(User $actor): array
    {
        $orgId = $this->assertCanView($actor);

        $events = Event::query()
            ->visibleTo($actor)
            ->with('tipoEvento:id,nombre,slug,color,icono')
            ->whereNull('evento_padre_id')
            ->whereNotIn('estado', [Event::ESTADO_CANCELADO])
            ->where(function ($query) {
                $query->whereDoesntHave('tipoEvento')
                    ->orWhereHas('tipoEvento', fn ($tipo) => $tipo->where('slug', '!=', TipoEvento::SLUG_ACTIVIDAD_ECONOMICA));
            })
            ->orderByDesc('starts_at')
            ->limit(60)
            ->get();

        $memberCount = $this->memberQuery($orgId)->count();
        $eventIds = $events->pluck('id')->all() ?: [0];
        $presentCounts = EventoAsistencia::query()
            ->where('organizacion_id', $orgId)
            ->whereIn('estado', [EventoAsistencia::ESTADO_PRESENTE, EventoAsistencia::ESTADO_PUNTUAL])
            ->whereIn('evento_id', $eventIds)
            ->selectRaw('evento_id, COUNT(*) as total')
            ->groupBy('evento_id')
            ->pluck('total', 'evento_id');
        $takenCounts = EventoAsistencia::query()
            ->where('organizacion_id', $orgId)
            ->whereIn('evento_id', $eventIds)
            ->selectRaw('evento_id, COUNT(*) as total')
            ->groupBy('evento_id')
            ->pluck('total', 'evento_id');

        return $events->map(function (Event $event) use ($memberCount, $presentCounts, $takenCounts) {
            return [
                ...$this->eventPayload($event),
                'integrantes_count' => $memberCount,
                'presentes_count' => (int) ($presentCounts[$event->id] ?? 0),
                'asistencias_count' => (int) ($takenCounts[$event->id] ?? 0),
            ];
        })->values()->all();
    }

    /**
     * @return array<string, mixed>
     */
    public function show(User $actor, Event $event): array
    {
        $orgId = $this->assertCanView($actor);
        $this->assertVisibleEvent($actor, $event);

        $asistencias = EventoAsistencia::query()
            ->where('evento_id', $event->id)
            ->where('organizacion_id', $orgId)
            ->get()
            ->keyBy(fn (EventoAsistencia $row) => (int) $row->persona_id);

        $integrantes = $this->members($orgId)->map(function (Persona $persona) use ($asistencias) {
            $row = $asistencias->get((int) $persona->id);

            return [
                'persona_id' => (int) $persona->id,
                'full_name' => $persona->full_name,
                'identificacion' => $persona->identificacion,
                'foto_url' => $this->photoUrl($persona),
                'estado' => $row?->estado,
                'notas' => $row?->notas,
            ];
        })->values()->all();

        return [
            'evento' => $this->eventPayload($event),
            'integrantes' => $integrantes,
            'resumen' => $this->resumen($integrantes),
        ];
    }

    /**
     * @param  list<int>  $presenteIds
     * @param  list<int>  $justificadoIds
     * @param  list<int>  $puntualIds
     * @return array<string, mixed>
     */
    public function sync(
        User $actor,
        Event $event,
        array $presenteIds,
        array $justificadoIds = [],
        array $puntualIds = [],
    ): array {
        $orgId = $this->assertCanUpdate($actor);
        $this->assertVisibleEvent($actor, $event);

        $memberIds = $this->members($orgId)->pluck('id')->map(fn ($id) => (int) $id)->all();
        $puntualIds = array_values(array_unique(array_map('intval', $puntualIds)));
        $presenteIds = array_values(array_unique(array_diff(
            array_map('intval', $presenteIds),
            $puntualIds,
        )));
        $justificadoIds = array_values(array_unique(array_diff(
            array_map('intval', $justificadoIds),
            $presenteIds,
            $puntualIds,
        )));

        foreach ([...$presenteIds, ...$justificadoIds, ...$puntualIds] as $personaId) {
            if (! in_array($personaId, $memberIds, true)) {
                throw ValidationException::withMessages([
                    'persona_ids' => ['Hay personas que no son integrantes de este club.'],
                ]);
            }
        }

        DB::transaction(function () use ($event, $orgId, $memberIds, $presenteIds, $justificadoIds, $puntualIds, $actor) {
            foreach ($memberIds as $personaId) {
                $estado = EventoAsistencia::ESTADO_AUSENTE;
                if (in_array($personaId, $puntualIds, true)) {
                    $estado = EventoAsistencia::ESTADO_PUNTUAL;
                } elseif (in_array($personaId, $presenteIds, true)) {
                    $estado = EventoAsistencia::ESTADO_PRESENTE;
                } elseif (in_array($personaId, $justificadoIds, true)) {
                    $estado = EventoAsistencia::ESTADO_JUSTIFICADO;
                }

                EventoAsistencia::query()->updateOrCreate(
                    [
                        'evento_id' => $event->id,
                        'organizacion_id' => $orgId,
                        'persona_id' => $personaId,
                    ],
                    [
                        'estado' => $estado,
                        'registrado_por' => $actor->id,
                    ],
                );
            }
        });

        return $this->show($actor, $event->fresh());
    }

    /**
     * @return array<string, mixed>
     */
    public function ranking(User $actor, ?string $desde = null, ?string $hasta = null): array
    {
        $orgId = $this->assertCanView($actor);
        $takenEventIds = $this->takenEventIds($actor, $orgId, $desde, $hasta);
        $eventos = $takenEventIds->count();
        $counts = EventoAsistencia::query()
            ->where('organizacion_id', $orgId)
            ->whereIn('evento_id', $takenEventIds->isEmpty() ? [0] : $takenEventIds)
            ->selectRaw('persona_id, estado, COUNT(*) as total')
            ->groupBy('persona_id', 'estado')
            ->get()
            ->groupBy('persona_id');

        $integrantes = $this->members($orgId)
            ->map(function (Persona $persona) use ($counts, $eventos) {
                $rows = $counts->get((int) $persona->id) ?? collect();
                $presentesTarde = (int) ($rows->firstWhere('estado', EventoAsistencia::ESTADO_PRESENTE)?->total ?? 0);
                $puntuales = (int) ($rows->firstWhere('estado', EventoAsistencia::ESTADO_PUNTUAL)?->total ?? 0);
                $ausentes = (int) ($rows->firstWhere('estado', EventoAsistencia::ESTADO_AUSENTE)?->total ?? 0);
                $justificados = (int) ($rows->firstWhere('estado', EventoAsistencia::ESTADO_JUSTIFICADO)?->total ?? 0);
                $presentes = $presentesTarde + $puntuales;
                $marcados = $presentes + $ausentes + $justificados;
                $porcentaje = $eventos > 0 ? (int) round(($presentes / $eventos) * 100) : 0;

                return [
                    'persona_id' => (int) $persona->id,
                    'full_name' => $persona->full_name,
                    'identificacion' => $persona->identificacion,
                    'foto_url' => $this->photoUrl($persona),
                    'presentes' => $presentes,
                    'puntuales' => $puntuales,
                    'ausentes' => $ausentes,
                    'justificados' => $justificados,
                    'sin_marcar' => max($eventos - $marcados, 0),
                    'eventos' => $eventos,
                    'puntos' => $presentes,
                    'porcentaje' => $porcentaje,
                ];
            })
            ->sortBy([
                ['puntos', 'desc'],
                ['puntuales', 'desc'],
                ['full_name', 'asc'],
            ])
            ->values()
            ->all();

        return [
            'eventos' => $eventos,
            'desde' => $desde,
            'hasta' => $hasta,
            'integrantes' => $integrantes,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function memberHistory(User $actor, int $personaId, ?string $desde = null, ?string $hasta = null): array
    {
        $orgId = $this->assertCanView($actor);
        abort_unless(
            PersonaOrganizacion::query()
                ->where('organizacion_id', $orgId)
                ->where('persona_id', $personaId)
                ->where('estado', true)
                ->exists(),
            Response::HTTP_NOT_FOUND,
            'Esa persona no es integrante de este club.',
        );

        $persona = $this->members($orgId)->firstWhere('id', $personaId);
        abort_unless($persona instanceof Persona, Response::HTTP_NOT_FOUND);

        $takenEventIds = $this->takenEventIds($actor, $orgId, $desde, $hasta);
        $events = Event::query()
            ->with('tipoEvento:id,nombre,slug,color,icono')
            ->whereIn('id', $takenEventIds->isEmpty() ? [0] : $takenEventIds)
            ->orderByDesc('starts_at')
            ->get();
        $asistencias = EventoAsistencia::query()
            ->where('organizacion_id', $orgId)
            ->where('persona_id', $personaId)
            ->whereIn('evento_id', $takenEventIds->isEmpty() ? [0] : $takenEventIds)
            ->get()
            ->keyBy(fn (EventoAsistencia $row) => (int) $row->evento_id);

        $registros = $events->map(function (Event $event) use ($asistencias) {
            $estado = $asistencias->get((int) $event->id)?->estado;

            return [
                ...$this->eventPayload($event),
                'estado' => $estado,
                'puntos' => $this->puntosPorEstado($estado),
            ];
        })->values()->all();

        $presentes = collect($registros)->whereIn('estado', [
            EventoAsistencia::ESTADO_PRESENTE,
            EventoAsistencia::ESTADO_PUNTUAL,
        ])->count();
        $puntuales = collect($registros)->where('estado', EventoAsistencia::ESTADO_PUNTUAL)->count();
        $justificados = collect($registros)->where('estado', EventoAsistencia::ESTADO_JUSTIFICADO)->count();
        $ausentes = collect($registros)->where('estado', EventoAsistencia::ESTADO_AUSENTE)->count();
        $eventos = count($registros);
        $marcados = $presentes + $justificados + $ausentes;

        return [
            'persona_id' => (int) $persona->id,
            'full_name' => $persona->full_name,
            'foto_url' => $this->photoUrl($persona),
            'desde' => $desde,
            'hasta' => $hasta,
            'presentes' => $presentes,
            'puntuales' => $puntuales,
            'justificados' => $justificados,
            'ausentes' => $ausentes,
            'sin_marcar' => max($eventos - $marcados, 0),
            'eventos' => $eventos,
            'puntos' => $presentes,
            'porcentaje' => $eventos > 0 ? (int) round(($presentes / $eventos) * 100) : 0,
            'registros' => $registros,
        ];
    }

    /**
     * @return \Illuminate\Support\Collection<int, int>
     */
    private function takenEventIds(User $actor, int $orgId, ?string $desde, ?string $hasta)
    {
        $eventIds = Event::query()
            ->visibleTo($actor)
            ->whereNull('evento_padre_id')
            ->whereNotIn('estado', [Event::ESTADO_CANCELADO])
            ->where(function ($query) {
                $query->whereDoesntHave('tipoEvento')
                    ->orWhereHas('tipoEvento', fn ($tipo) => $tipo->where('slug', '!=', TipoEvento::SLUG_ACTIVIDAD_ECONOMICA));
            })
            ->when($desde, fn ($query) => $query->whereDate('starts_at', '>=', $desde))
            ->when($hasta, fn ($query) => $query->whereDate('starts_at', '<=', $hasta))
            ->pluck('id');

        return EventoAsistencia::query()
            ->where('organizacion_id', $orgId)
            ->whereIn('evento_id', $eventIds->isEmpty() ? [0] : $eventIds)
            ->distinct()
            ->pluck('evento_id');
    }

    private function puntosPorEstado(?string $estado): int
    {
        return in_array($estado, [
            EventoAsistencia::ESTADO_PRESENTE,
            EventoAsistencia::ESTADO_PUNTUAL,
        ], true) ? 1 : 0;
    }

    private function assertCanView(User $actor): int
    {
        $orgId = AppSetting::resolveOrganizacionId();
        abort_unless($orgId, Response::HTTP_FORBIDDEN, 'Debes tener una organización activa.');
        abort_unless(
            $this->actorTakesAttendance($actor) || $actor->hasPermission('asistencia.view'),
            Response::HTTP_FORBIDDEN,
            'No puedes ver la asistencia del club.',
        );

        return $orgId;
    }

    private function assertCanUpdate(User $actor): int
    {
        $orgId = $this->assertCanView($actor);
        abort_unless(
            $this->actorTakesAttendance($actor) || $actor->hasPermission('asistencia.update'),
            Response::HTTP_FORBIDDEN,
            'No puedes registrar asistencia.',
        );

        return $orgId;
    }

    private function actorTakesAttendance(User $actor): bool
    {
        return count(array_intersect($actor->roleNames(), [
            'director',
            'subdirector',
            'secretario',
        ])) > 0;
    }

    private function assertVisibleEvent(User $actor, Event $event): void
    {
        abort_unless($event->evento_padre_id === null, Response::HTTP_NOT_FOUND);
        abort_unless(
            $event->estado !== Event::ESTADO_CANCELADO && $event->isVisibleTo($actor),
            Response::HTTP_FORBIDDEN,
            'No puedes tomar asistencia de este evento.',
        );
        abort_unless(
            ! $event->isActividadEconomica(),
            Response::HTTP_UNPROCESSABLE_ENTITY,
            'La asistencia no aplica a actividades económicas.',
        );
    }

    /**
     * @return \Illuminate\Support\Collection<int, Persona>
     */
    private function members(int $organizacionId)
    {
        $personaIds = $this->memberQuery($organizacionId)->pluck('persona_id');

        return Persona::query()
            ->with('user:id,persona_id,avatar_url')
            ->whereIn('id', $personaIds->isEmpty() ? [0] : $personaIds)
            ->orderBy('apellido1')
            ->orderBy('nombre1')
            ->get();
    }

    private function memberQuery(int $organizacionId)
    {
        return PersonaOrganizacion::query()
            ->where('organizacion_id', $organizacionId)
            ->where('estado', true);
    }

    private function photoUrl(Persona $persona): ?string
    {
        return $this->publicFiles->url($persona->foto)
            ?? $this->publicFiles->url($persona->user?->avatar_url);
    }

    /**
     * @return array<string, mixed>
     */
    private function eventPayload(Event $event): array
    {
        return [
            'id' => (int) $event->id,
            'name' => $event->name,
            'descripcion' => $event->descripcion,
            'lugar' => $event->lugar,
            'starts_at' => $event->starts_at?->toIso8601String(),
            'ends_at' => $event->ends_at?->toIso8601String(),
            'estado' => $event->estado,
            'image_url' => $event->image_url,
            'tipo_evento' => $event->tipoEvento
                ? [
                    'id' => $event->tipoEvento->id,
                    'nombre' => $event->tipoEvento->nombre,
                    'slug' => $event->tipoEvento->slug,
                    'color' => $event->tipoEvento->color,
                    'icono' => $event->tipoEvento->icono,
                ]
                : null,
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $integrantes
     * @return array<string, int>
     */
    private function resumen(array $integrantes): array
    {
        $presentes = 0;
        $puntuales = 0;
        $ausentes = 0;
        $justificados = 0;
        $sinMarcar = 0;

        foreach ($integrantes as $row) {
            match ($row['estado'] ?? null) {
                EventoAsistencia::ESTADO_PUNTUAL => $puntuales++,
                EventoAsistencia::ESTADO_PRESENTE => $presentes++,
                EventoAsistencia::ESTADO_AUSENTE => $ausentes++,
                EventoAsistencia::ESTADO_JUSTIFICADO => $justificados++,
                default => $sinMarcar++,
            };
        }

        return [
            'total' => count($integrantes),
            'presentes' => $presentes + $puntuales,
            'puntuales' => $puntuales,
            'ausentes' => $ausentes,
            'justificados' => $justificados,
            'sin_marcar' => $sinMarcar,
        ];
    }
}
