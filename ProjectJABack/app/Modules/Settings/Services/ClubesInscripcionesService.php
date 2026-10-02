<?php

namespace App\Modules\Settings\Services;

use App\Models\User;
use App\Modules\Clubs\Models\Persona;
use App\Modules\Events\Models\Event;
use App\Modules\Events\Models\EventoInscripcion;
use App\Modules\Events\Models\EventoInscripcionPersona;
use App\Modules\Organizations\Models\Organizacion;
use App\Modules\Organizations\Models\PersonaOrganizacion;
use App\Modules\Settings\Models\AppSetting;
use App\Modules\Shared\Services\PublicFileService;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\Response;

final class ClubesInscripcionesService
{
    public function __construct(private readonly PublicFileService $publicFiles) {}

    /**
     * @return array<string, mixed>
     */
    public function show(User $actor, Event $event): array
    {
        $orgId = $this->assertCanViewBoard($actor);
        $this->assertEventoInscribible($actor, $event, $orgId);

        return $this->roster($event, $orgId, $actor);
    }

    /**
     * @return array<string, mixed>
     */
    public function me(User $actor, Event $event): array
    {
        [$orgId, $personaId] = $this->assertCanSelf($actor, $event);

        return $this->selfPayload($event, $orgId, $personaId);
    }

    /**
     * @return array<string, mixed>
     */
    public function join(User $actor, Event $event, bool $inscrito): array
    {
        [$orgId, $personaId] = $this->assertCanSelf($actor, $event);
        $this->assertPublicado($event);

        DB::transaction(function () use ($event, $orgId, $personaId, $inscrito) {
            $this->syncPersonas($event, $orgId, [$personaId => $inscrito], [$personaId]);
        });

        return $this->selfPayload($event->fresh(), $orgId, $personaId);
    }

    /**
     * @param  list<int>  $personaIds
     * @return array<string, mixed>
     */
    public function sync(User $actor, Event $event, array $personaIds): array
    {
        $orgId = $this->assertCanUpdateBoard($actor);
        $this->assertEventoInscribible($actor, $event, $orgId);
        $this->assertPublicado($event);

        $memberIds = $this->members($orgId)->pluck('id')->map(fn ($id) => (int) $id)->all();
        $relatedIds = $this->relatedPeople($orgId)->pluck('id')->map(fn ($id) => (int) $id)->all();
        $allowedIds = array_values(array_unique([...$memberIds, ...$relatedIds]));
        $personaIds = array_values(array_unique(array_map('intval', $personaIds)));
        foreach ($personaIds as $personaId) {
            if (! in_array($personaId, $allowedIds, true)) {
                throw ValidationException::withMessages([
                    'persona_ids' => ['Hay personas que no pertenecen al club, a la iglesia padre ni a un club hermano.'],
                ]);
            }
        }

        $currentIds = $this->inscritosIds($event->id, $orgId);
        $touchIds = array_values(array_unique([...$memberIds, ...$currentIds, ...$personaIds]));

        DB::transaction(function () use ($event, $orgId, $memberIds, $allowedIds, $currentIds, $touchIds, $personaIds) {
            $flags = [];
            foreach ($touchIds as $personaId) {
                if (! in_array($personaId, $allowedIds, true) && ! in_array($personaId, $currentIds, true)) {
                    continue;
                }
                $flags[$personaId] = in_array($personaId, $personaIds, true);
            }
            $this->syncPersonas($event, $orgId, $flags, $memberIds);
        });

        return $this->roster($event->fresh(), $orgId, $actor);
    }

    /**
     * @param  array<int, bool>  $flags
     * @param  list<int>  $memberIds
     */
    private function syncPersonas(Event $event, int $orgId, array $flags, array $memberIds): void
    {
        $inscripcion = EventoInscripcion::query()->firstOrCreate(
            [
                'evento_id' => $event->id,
                'organizacion_id' => $orgId,
                'tipo' => EventoInscripcion::TIPO_CLUB,
            ],
            [
                'estado' => EventoInscripcion::ESTADO_APROBADA,
            ],
        );
        if ($inscripcion->estado === EventoInscripcion::ESTADO_BORRADOR
            || $inscripcion->estado === EventoInscripcion::ESTADO_NO_APROBADA) {
            $inscripcion->estado = EventoInscripcion::ESTADO_APROBADA;
            $inscripcion->save();
        }

        foreach ($flags as $personaId => $inscrito) {
            EventoInscripcionPersona::query()->updateOrCreate(
                [
                    'inscripcion_id' => $inscripcion->id,
                    'persona_id' => $personaId,
                ],
                [
                    'tipo' => in_array((int) $personaId, $memberIds, true)
                        ? EventoInscripcionPersona::TIPO_MIEMBRO
                        : EventoInscripcionPersona::TIPO_ACOMPANANTE,
                    'estado' => $inscrito
                        ? EventoInscripcionPersona::ESTADO_CONFIRMADA
                        : EventoInscripcionPersona::ESTADO_CANCELADA,
                ],
            );
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function roster(Event $event, int $orgId, User $actor): array
    {
        $inscritos = $this->inscritosIds($event->id, $orgId);
        $related = $this->relatedPeople($orgId);
        $integrantes = $this->members($orgId)->map(function (Persona $persona) use ($inscritos) {
            return $this->personRow($persona, in_array((int) $persona->id, $inscritos, true), 'club');
        })->values();

        $externosInscritos = $related
            ->filter(fn (Persona $persona) => in_array((int) $persona->id, $inscritos, true))
            ->map(fn (Persona $persona) => $this->personRow(
                $persona,
                true,
                'externo',
                $persona->relacion_organizacion,
            ))
            ->values();

        $candidatos = $related
            ->reject(fn (Persona $persona) => in_array((int) $persona->id, $inscritos, true))
            ->map(fn (Persona $persona) => $this->personRow(
                $persona,
                false,
                'externo',
                $persona->relacion_organizacion,
            ))
            ->values()
            ->all();

        $roster = $integrantes->concat($externosInscritos)->values()->all();
        $yo = $actor->persona_id ? in_array((int) $actor->persona_id, $inscritos, true) : false;

        return [
            'evento' => $this->eventPayload($event, count($inscritos), $yo),
            'integrantes' => $roster,
            'candidatos' => $candidatos,
            'resumen' => [
                'total' => $integrantes->count(),
                'inscritos' => count($inscritos),
                'sin_inscribir' => $integrantes->where('inscrito', false)->count(),
                'externos' => $externosInscritos->count(),
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function personRow(Persona $persona, bool $inscrito, string $origen, ?string $organizacion = null): array
    {
        return [
            'persona_id' => (int) $persona->id,
            'full_name' => $persona->full_name,
            'identificacion' => $persona->identificacion,
            'foto_url' => $this->photoUrl($persona),
            'inscrito' => $inscrito,
            'origen' => $origen,
            'organizacion' => $organizacion,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function selfPayload(Event $event, int $orgId, int $personaId): array
    {
        $inscritos = $this->inscritosIds($event->id, $orgId);

        return [
            'evento' => $this->eventPayload($event, count($inscritos), in_array($personaId, $inscritos, true)),
            'inscrito' => in_array($personaId, $inscritos, true),
        ];
    }

    /**
     * @return list<int>
     */
    private function inscritosIds(int $eventoId, int $orgId): array
    {
        return EventoInscripcionPersona::query()
            ->join('evento_inscripcion', 'evento_inscripcion.id', '=', 'evento_inscripcion_persona.inscripcion_id')
            ->where('evento_inscripcion.evento_id', $eventoId)
            ->where('evento_inscripcion.organizacion_id', $orgId)
            ->whereNotIn('evento_inscripcion.estado', [
                EventoInscripcion::ESTADO_NO_APROBADA,
                EventoInscripcion::ESTADO_BORRADOR,
            ])
            ->where('evento_inscripcion_persona.estado', '!=', EventoInscripcionPersona::ESTADO_CANCELADA)
            ->whereNotNull('evento_inscripcion_persona.persona_id')
            ->pluck('evento_inscripcion_persona.persona_id')
            ->map(fn ($id) => (int) $id)
            ->all();
    }

    /**
     * @return \Illuminate\Support\Collection<int, Persona>
     */
    private function members(int $orgId)
    {
        $personaIds = PersonaOrganizacion::query()
            ->where('organizacion_id', $orgId)
            ->where('estado', true)
            ->pluck('persona_id');

        return Persona::query()
            ->with('user:id,persona_id,avatar_url')
            ->whereIn('id', $personaIds->isEmpty() ? [0] : $personaIds)
            ->orderBy('apellido1')
            ->orderBy('nombre1')
            ->get();
    }

    /**
     * Personas de la iglesia padre y clubes hermanos, sin integrantes del club activo.
     *
     * @return \Illuminate\Support\Collection<int, Persona>
     */
    private function relatedPeople(int $orgId)
    {
        $orgIds = $this->relatedOrganizationIds($orgId);
        if ($orgIds === []) {
            return collect();
        }

        $clubMemberIds = PersonaOrganizacion::query()
            ->where('organizacion_id', $orgId)
            ->where('estado', true)
            ->pluck('persona_id');

        $memberships = PersonaOrganizacion::query()
            ->with('organizacion:id,nombre,tipo_organizacion_id')
            ->whereIn('organizacion_id', $orgIds)
            ->where('estado', true)
            ->when($clubMemberIds->isNotEmpty(), fn ($query) => $query->whereNotIn('persona_id', $clubMemberIds))
            ->get()
            ->sortBy(fn (PersonaOrganizacion $row) => (int) $row->organizacion?->tipo_organizacion_id === Organizacion::TIPO_CLUB ? 0 : 1)
            ->unique('persona_id')
            ->values();

        $personaIds = $memberships->pluck('persona_id');
        if ($personaIds->isEmpty()) {
            return collect();
        }

        $orgByPersona = $memberships
            ->mapWithKeys(fn (PersonaOrganizacion $row) => [(int) $row->persona_id => $row->organizacion?->nombre]);

        return Persona::query()
            ->with('user:id,persona_id,avatar_url')
            ->whereIn('id', $personaIds)
            ->orderBy('apellido1')
            ->orderBy('nombre1')
            ->get()
            ->each(function (Persona $persona) use ($orgByPersona) {
                $persona->setAttribute('relacion_organizacion', $orgByPersona[(int) $persona->id] ?? null);
            });
    }

    /**
     * @return list<int>
     */
    private function relatedOrganizationIds(int $orgId): array
    {
        $parentId = Organizacion::query()
            ->where('id', $orgId)
            ->value('organizacion_padre_id');
        if (! $parentId) {
            return [];
        }

        $siblingIds = Organizacion::query()
            ->where('organizacion_padre_id', $parentId)
            ->where('tipo_organizacion_id', Organizacion::TIPO_CLUB)
            ->where('id', '!=', $orgId)
            ->pluck('id')
            ->map(fn ($id) => (int) $id)
            ->all();

        return array_values(array_unique([(int) $parentId, ...$siblingIds]));
    }

    /**
     * @return array<string, mixed>
     */
    private function eventPayload(Event $event, int $inscritosCount, bool $inscrito): array
    {
        $event->loadMissing('tipoEvento');

        return [
            'id' => (int) $event->id,
            'name' => $event->name,
            'estado' => $event->estado,
            'starts_at' => $event->starts_at?->toIso8601String(),
            'image_url' => $event->image_url,
            'banner_url' => $event->banner_url,
            'inscritos_count' => $inscritosCount,
            'inscrito' => $inscrito,
            'tipo_evento' => $event->tipoEvento
                ? [
                    'id' => $event->tipoEvento->id,
                    'nombre' => $event->tipoEvento->nombre,
                    'slug' => $event->tipoEvento->slug,
                ]
                : null,
        ];
    }

    private function photoUrl(Persona $persona): ?string
    {
        return $this->publicFiles->url($persona->foto)
            ?? $this->publicFiles->url($persona->user?->avatar_url);
    }

    /**
     * @return array{0: int, 1: int}
     */
    private function assertCanSelf(User $actor, Event $event): array
    {
        $orgId = AppSetting::resolveOrganizacionId();
        abort_unless($orgId, Response::HTTP_FORBIDDEN, 'Debes tener una organización activa.');
        abort_unless($actor->persona_id, Response::HTTP_FORBIDDEN, 'Tu usuario no tiene persona asociada.');
        abort_unless(
            PersonaOrganizacion::query()
                ->where('organizacion_id', $orgId)
                ->where('persona_id', $actor->persona_id)
                ->where('estado', true)
                ->exists(),
            Response::HTTP_FORBIDDEN,
            'No eres integrante de este club.',
        );
        $this->assertEventoInscribible($actor, $event, $orgId);

        return [$orgId, (int) $actor->persona_id];
    }

    private function assertCanViewBoard(User $actor): int
    {
        $orgId = AppSetting::resolveOrganizacionId();
        abort_unless($orgId, Response::HTTP_FORBIDDEN, 'Debes tener una organización activa.');
        abort_unless(
            $this->actorManagesInscripciones($actor) || $actor->hasPermission('events.update'),
            Response::HTTP_FORBIDDEN,
            'No puedes ver las inscripciones del club.',
        );

        return $orgId;
    }

    private function assertCanUpdateBoard(User $actor): int
    {
        $orgId = $this->assertCanViewBoard($actor);
        abort_unless(
            $this->actorManagesInscripciones($actor) || $actor->hasPermission('events.update'),
            Response::HTTP_FORBIDDEN,
            'No puedes inscribir integrantes.',
        );

        return $orgId;
    }

    private function actorManagesInscripciones(User $actor): bool
    {
        return count(array_intersect($actor->roleNames(), [
            'director',
            'subdirector',
            'secretario',
            'tesorero',
        ])) > 0;
    }

    private function assertEventoInscribible(User $actor, Event $event, int $orgId): void
    {
        abort_unless(
            (int) $event->organizacion_id === $orgId
                && $event->estado !== Event::ESTADO_CANCELADO
                && $event->isVisibleTo($actor),
            Response::HTTP_FORBIDDEN,
            'Este evento no pertenece a tu club.',
        );
        abort_unless(
            $event->aceptaInscripcionClub(),
            Response::HTTP_UNPROCESSABLE_ENTITY,
            'Solo se inscribe en campamentos, especialidades e investiduras.',
        );
    }

    private function assertPublicado(Event $event): void
    {
        abort_unless(
            $event->inscripcionClubAbierta(),
            Response::HTTP_UNPROCESSABLE_ENTITY,
            'La inscripción solo está abierta mientras el evento está publicado.',
        );
    }
}
