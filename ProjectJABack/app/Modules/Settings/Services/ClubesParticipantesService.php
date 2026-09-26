<?php

namespace App\Modules\Settings\Services;

use App\Models\User;
use App\Modules\Clubs\Models\Persona;
use App\Modules\Events\Models\Event;
use App\Modules\Events\Models\EventoParticipacion;
use App\Modules\Events\Models\EventoParticipacionVenta;
use App\Modules\Events\Models\EventoProductoServicio;
use App\Modules\Events\Models\ProductoServicio;
use App\Modules\Organizations\Models\PersonaOrganizacion;
use App\Modules\Settings\Models\AppSetting;
use App\Modules\Shared\Services\PublicFileService;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\Response;

final class ClubesParticipantesService
{
    public function __construct(private readonly PublicFileService $publicFiles) {}

    /**
     * @return array<string, mixed>
     */
    public function show(User $actor, Event $event): array
    {
        $orgId = $this->assertCanView($actor);
        $this->assertEconomicEvent($actor, $event);

        $marcados = EventoParticipacion::query()
            ->with('ventas')
            ->where('evento_id', $event->id)
            ->where('organizacion_id', $orgId)
            ->get()
            ->keyBy(fn (EventoParticipacion $row) => (int) $row->persona_id);

        $servicios = $this->eventServices($event);
        $servicioIds = array_map(fn (array $item) => (int) $item['id'], $servicios);

        $integrantes = $this->members($orgId)->map(
            fn (Persona $persona) => $this->mapIntegrante($persona, $marcados->get((int) $persona->id), $servicioIds)
        )->values()->all();

        return [
            'evento' => $this->eventPayload($event),
            'servicios' => $servicios,
            'integrantes' => $integrantes,
            'resumen' => $this->resumen($integrantes),
        ];
    }

    /**
     * @param  list<int>  $participaIds
     * @param  list<array<string, mixed>>  $participantes
     * @return array<string, mixed>
     */
    public function sync(User $actor, Event $event, array $participaIds, array $participantes = []): array
    {
        $orgId = $this->assertCanUpdate($actor);
        $this->assertEconomicEvent($actor, $event);

        $memberIds = $this->members($orgId)->pluck('id')->map(fn ($id) => (int) $id)->all();
        $entries = $this->normalizeEntries($participaIds, $participantes, $memberIds);
        $servicioIds = array_map(
            fn (array $item) => (int) $item['id'],
            $this->eventServices($event),
        );

        DB::transaction(function () use ($event, $orgId, $memberIds, $entries, $servicioIds) {
            foreach ($memberIds as $personaId) {
                $entry = $entries[$personaId] ?? ['participa' => null, 'ventas' => []];
                if ($entry['participa'] === null) {
                    EventoParticipacion::query()
                        ->where('evento_id', $event->id)
                        ->where('organizacion_id', $orgId)
                        ->where('persona_id', $personaId)
                        ->delete();

                    continue;
                }

                $row = EventoParticipacion::query()->updateOrCreate(
                    [
                        'evento_id' => $event->id,
                        'organizacion_id' => $orgId,
                        'persona_id' => $personaId,
                    ],
                    [
                        'participa' => $entry['participa'],
                    ],
                );

                $this->syncVentas($row, $entry['participa'] ? $entry['ventas'] : [], $servicioIds);
            }
        });

        return $this->show($actor, $event->fresh());
    }

    /**
     * @return array<string, mixed>
     */
    public function self(User $actor, Event $event): array
    {
        [$orgId, $personaId] = $this->assertCanJoin($actor, $event);
        $servicios = $this->eventServices($event);
        $servicioIds = array_map(fn (array $item) => (int) $item['id'], $servicios);
        $persona = Persona::query()->findOrFail($personaId);
        $row = EventoParticipacion::query()
            ->with('ventas')
            ->where('evento_id', $event->id)
            ->where('organizacion_id', $orgId)
            ->where('persona_id', $personaId)
            ->first();

        return [
            'evento' => $this->eventPayload($event),
            'servicios' => $servicios,
            'integrante' => $this->mapIntegrante($persona, $row, $servicioIds),
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $ventas
     * @return array<string, mixed>
     */
    public function join(User $actor, Event $event, bool $participa, array $ventas = []): array
    {
        [$orgId, $personaId] = $this->assertCanJoin($actor, $event);
        $servicioIds = array_map(fn (array $item) => (int) $item['id'], $this->eventServices($event));
        $normalized = $this->normalizeVentas($ventas);

        DB::transaction(function () use ($event, $orgId, $personaId, $participa, $normalized, $servicioIds) {
            $row = EventoParticipacion::query()->updateOrCreate(
                [
                    'evento_id' => $event->id,
                    'organizacion_id' => $orgId,
                    'persona_id' => $personaId,
                ],
                [
                    'participa' => $participa,
                ],
            );

            $this->syncVentas($row, $participa ? $normalized : [], $servicioIds);
        });

        return $this->self($actor, $event->fresh());
    }

    /**
     * @param  list<int>  $participaIds
     * @param  list<array<string, mixed>>  $participantes
     * @param  list<int>  $memberIds
     * @return array<int, array{participa: bool|null, ventas: array<int, int>}>
     */
    private function normalizeEntries(array $participaIds, array $participantes, array $memberIds): array
    {
        $entries = [];
        if ($participantes !== []) {
            foreach ($participantes as $item) {
                $personaId = (int) ($item['persona_id'] ?? 0);
                if (! in_array($personaId, $memberIds, true)) {
                    throw ValidationException::withMessages([
                        'participantes' => ['Hay personas que no son integrantes de este club.'],
                    ]);
                }
                $participa = array_key_exists('participa', $item) ? $item['participa'] : null;
                $entries[$personaId] = [
                    'participa' => is_bool($participa) ? $participa : null,
                    'ventas' => $this->normalizeVentas($item['ventas'] ?? []),
                ];
            }

            return $entries;
        }

        $participaIds = array_values(array_unique(array_map('intval', $participaIds)));
        foreach ($participaIds as $personaId) {
            if (! in_array($personaId, $memberIds, true)) {
                throw ValidationException::withMessages([
                    'persona_ids' => ['Hay personas que no son integrantes de este club.'],
                ]);
            }
        }
        foreach ($memberIds as $personaId) {
            $entries[$personaId] = [
                'participa' => in_array($personaId, $participaIds, true),
                'ventas' => [],
            ];
        }

        return $entries;
    }

    /**
     * @param  list<array<string, mixed>>  $ventas
     * @return array<int, int>
     */
    private function normalizeVentas(array $ventas): array
    {
        $normalized = [];
        foreach ($ventas as $venta) {
            $productoId = (int) ($venta['producto_servicio_id'] ?? 0);
            $cantidad = max(0, (int) ($venta['cantidad'] ?? 0));
            if ($productoId > 0 && $cantidad > 0) {
                $normalized[$productoId] = $cantidad;
            }
        }

        return $normalized;
    }

    /**
     * @param  list<int>  $servicioIds
     * @return array<string, mixed>
     */
    private function mapIntegrante(Persona $persona, ?EventoParticipacion $row, array $servicioIds): array
    {
        $ventas = [];
        if ($row) {
            $ventas = $row->ventas
                ->filter(fn (EventoParticipacionVenta $venta) => in_array((int) $venta->producto_servicio_id, $servicioIds, true))
                ->map(fn (EventoParticipacionVenta $venta) => [
                    'producto_servicio_id' => (int) $venta->producto_servicio_id,
                    'cantidad' => (int) $venta->cantidad,
                ])
                ->values()
                ->all();
        }

        return [
            'persona_id' => (int) $persona->id,
            'full_name' => $persona->full_name,
            'identificacion' => $persona->identificacion,
            'participa' => $row ? (bool) $row->participa : null,
            'ventas' => $ventas,
        ];
    }

    /**
     * @param  array<int, int>  $ventas
     * @param  list<int>  $servicioIds
     */
    private function syncVentas(EventoParticipacion $row, array $ventas, array $servicioIds): void
    {
        $keep = [];
        foreach ($ventas as $productoId => $cantidad) {
            $productoId = (int) $productoId;
            if (! in_array($productoId, $servicioIds, true)) {
                throw ValidationException::withMessages([
                    'participantes' => ['Hay servicios que no pertenecen a este evento.'],
                ]);
            }
            $venta = EventoParticipacionVenta::query()->updateOrCreate(
                [
                    'evento_participacion_id' => $row->id,
                    'producto_servicio_id' => $productoId,
                ],
                ['cantidad' => (int) $cantidad],
            );
            $keep[] = (int) $venta->id;
        }

        $query = EventoParticipacionVenta::query()->where('evento_participacion_id', $row->id);
        if ($keep !== []) {
            $query->whereNotIn('id', $keep);
        }
        $query->delete();
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function eventServices(Event $event): array
    {
        return EventoProductoServicio::query()
            ->where('evento_id', $event->id)
            ->where('activo', true)
            ->with('producto')
            ->get()
            ->map(function (EventoProductoServicio $oferta) {
                $producto = $oferta->producto;
                if (! $producto instanceof ProductoServicio) {
                    return null;
                }

                return [
                    'id' => (int) $producto->id,
                    'nombre' => $producto->nombre,
                    'precio' => $producto->precio,
                    'icono' => $producto->icono,
                    'image_url' => $this->publicFiles->url($producto->image_path),
                ];
            })
            ->filter()
            ->values()
            ->all();
    }

    private function assertCanView(User $actor): int
    {
        $orgId = AppSetting::resolveOrganizacionId();
        abort_unless($orgId, Response::HTTP_FORBIDDEN, 'Debes tener una organización activa.');
        abort_unless(
            $this->actorManagesRoster($actor) || $actor->hasPermission('events.view'),
            Response::HTTP_FORBIDDEN,
            'No puedes ver los participantes del evento.',
        );

        return $orgId;
    }

    private function assertCanUpdate(User $actor): int
    {
        $orgId = $this->assertCanView($actor);
        abort_unless(
            $this->actorManagesRoster($actor)
            || $actor->hasPermission('events.update')
            || $actor->hasPermission('events.create_organization'),
            Response::HTTP_FORBIDDEN,
            'No puedes registrar participantes.',
        );

        return $orgId;
    }

    private function actorManagesRoster(User $actor): bool
    {
        return count(array_intersect($actor->roleNames(), [
            'director',
            'subdirector',
            'secretario',
        ])) > 0;
    }

    /**
     * @return array{0: int, 1: int}
     */
    private function assertCanJoin(User $actor, Event $event): array
    {
        $orgId = AppSetting::resolveOrganizacionId();
        abort_unless($orgId, Response::HTTP_FORBIDDEN, 'Debes tener una organización activa.');
        abort_unless(
            $actor->hasPermission('events.view'),
            Response::HTTP_FORBIDDEN,
            'No puedes participar en este evento.',
        );

        $personaId = (int) ($actor->persona_id ?? 0);
        abort_unless($personaId > 0, Response::HTTP_UNPROCESSABLE_ENTITY, 'Tu usuario no está vinculado a una persona.');
        abort_unless(
            PersonaOrganizacion::query()
                ->where('organizacion_id', $orgId)
                ->where('persona_id', $personaId)
                ->where('estado', true)
                ->exists(),
            Response::HTTP_FORBIDDEN,
            'Solo los integrantes del club pueden participar.',
        );

        $this->assertEconomicEvent($actor, $event);

        return [$orgId, $personaId];
    }

    private function assertEconomicEvent(User $actor, Event $event): void
    {
        abort_unless($event->evento_padre_id === null, Response::HTTP_NOT_FOUND);
        abort_unless(
            $event->estado !== Event::ESTADO_CANCELADO && $event->isVisibleTo($actor),
            Response::HTTP_FORBIDDEN,
            'No puedes gestionar los participantes de este evento.',
        );
        abort_unless(
            $event->isActividadEconomica(),
            Response::HTTP_UNPROCESSABLE_ENTITY,
            'Los participantes solo se registran en actividades económicas.',
        );
    }

    /**
     * @return \Illuminate\Support\Collection<int, Persona>
     */
    private function members(int $organizacionId)
    {
        $personaIds = PersonaOrganizacion::query()
            ->where('organizacion_id', $organizacionId)
            ->where('estado', true)
            ->pluck('persona_id');

        return Persona::query()
            ->whereIn('id', $personaIds->isEmpty() ? [0] : $personaIds)
            ->orderBy('apellido1')
            ->orderBy('nombre1')
            ->get();
    }

    /**
     * @return array<string, mixed>
     */
    private function eventPayload(Event $event): array
    {
        $event->loadMissing('tipoEvento:id,nombre,slug,color,icono');

        return [
            'id' => (int) $event->id,
            'name' => $event->name,
            'descripcion' => $event->descripcion,
            'lugar' => $event->lugar,
            'starts_at' => $event->starts_at?->toIso8601String(),
            'ends_at' => $event->ends_at?->toIso8601String(),
            'estado' => $event->estado,
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
        $participan = 0;
        $noParticipan = 0;
        $sinMarcar = 0;
        $unidades = 0;

        foreach ($integrantes as $row) {
            if ($row['participa'] === true) {
                $participan++;
                foreach ($row['ventas'] ?? [] as $venta) {
                    $unidades += (int) ($venta['cantidad'] ?? 0);
                }
            } elseif ($row['participa'] === false) {
                $noParticipan++;
            } else {
                $sinMarcar++;
            }
        }

        return [
            'total' => count($integrantes),
            'participan' => $participan,
            'no_participan' => $noParticipan,
            'sin_marcar' => $sinMarcar,
            'unidades' => $unidades,
        ];
    }
}
