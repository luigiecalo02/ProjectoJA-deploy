<?php

namespace App\Modules\Settings\Services;

use App\Models\User;
use App\Modules\Clubs\Models\Persona;
use App\Modules\Events\Models\Event;
use App\Modules\Events\Models\EventoParticipacion;
use App\Modules\Events\Models\EventoParticipacionAbono;
use App\Modules\Events\Models\EventoParticipacionVenta;
use App\Modules\Events\Models\EventoProductoServicio;
use App\Modules\Events\Models\ProductoServicio;
use App\Modules\Events\Services\ProductoServicioService;
use App\Modules\Organizations\Models\PersonaOrganizacion;
use App\Modules\Settings\Models\AppSetting;
use App\Modules\Settings\Models\ClubesCajaMovimiento;
use App\Modules\Settings\Models\ClubesGananciaEclesiastica;
use App\Modules\Shared\Services\PublicFileService;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\Response;

final class ClubesParticipantesService
{
    public function __construct(
        private readonly PublicFileService $publicFiles,
        private readonly AbonoPresentacion $presentacion,
        private readonly ProductoServicioService $productos,
        private readonly ClubesCuentasBancariasService $cuentas,
        private readonly EventoGananciaRegistro $ganancias,
    ) {}

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
        $abonos = EventoParticipacionAbono::query()
            ->where('organizacion_id', $orgId)
            ->where('evento_id', $event->id)
            ->orderByDesc('created_at')
            ->get()
            ->groupBy(fn (EventoParticipacionAbono $abono) => (int) $abono->persona_id);
        $ajustes = ClubesCajaMovimiento::ajustesParticipantes($orgId, (int) $event->id)[(int) $event->id] ?? [];

        $integrantes = $this->members($orgId)->map(
            fn (Persona $persona) => $this->mapIntegrante(
                $persona,
                $marcados->get((int) $persona->id),
                $servicioIds,
                $abonos->get((int) $persona->id),
                $actor,
                (float) ($ajustes[(int) $persona->id] ?? 0),
            )
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
        $this->assertParticipationOpen($event);

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

        return $this->show($actor, $this->refrescarGanancias($event));
    }

    /**
     * @param  list<array<string, mixed>>  $ventas
     * @return array<string, mixed>
     */
    public function entregar(User $actor, Event $event, int $personaId, array $ventas): array
    {
        $orgId = $this->assertCanUpdate($actor);
        $this->assertEconomicEvent($actor, $event);
        abort_unless(
            $event->estado === Event::ESTADO_EN_PROCESO,
            Response::HTTP_UNPROCESSABLE_ENTITY,
            'Solo puedes confirmar la entrega mientras la actividad está en proceso.',
        );

        $row = EventoParticipacion::query()
            ->where('evento_id', $event->id)
            ->where('organizacion_id', $orgId)
            ->where('persona_id', $personaId)
            ->where('participa', true)
            ->first();
        abort_unless(
            $row instanceof EventoParticipacion,
            Response::HTTP_UNPROCESSABLE_ENTITY,
            'Esa persona no está participando.',
        );

        $servicioIds = array_map(
            fn (array $item) => (int) $item['id'],
            $this->eventServices($event),
        );

        DB::transaction(function () use ($row, $ventas, $servicioIds) {
            foreach ($ventas as $venta) {
                $productoId = (int) ($venta['producto_servicio_id'] ?? 0);
                $entregada = max(0, (int) ($venta['cantidad_entregada'] ?? 0));
                if (! in_array($productoId, $servicioIds, true)) {
                    throw ValidationException::withMessages([
                        'ventas' => ['Hay servicios que no pertenecen a este evento.'],
                    ]);
                }

                $linea = EventoParticipacionVenta::query()
                    ->where('evento_participacion_id', $row->id)
                    ->where('producto_servicio_id', $productoId)
                    ->first();
                if (! $linea || (int) $linea->cantidad <= 0) {
                    throw ValidationException::withMessages([
                        'ventas' => ['Solo puedes entregar cantidades que el participante pidió.'],
                    ]);
                }

                $linea->cantidad_entregada = $entregada;
                $linea->save();
            }
        });

        return $this->show($actor, $this->refrescarGanancias($event));
    }

    /**
     * @return array<string, mixed>
     */
    public function self(User $actor, Event $event): array
    {
        [$orgId, $personaId] = $this->assertCanJoin($actor, $event);
        $servicios = $this->eventServices($event);
        $servicioIds = array_map(fn (array $item) => (int) $item['id'], $servicios);
        $persona = Persona::query()->with('user')->findOrFail($personaId);
        $row = EventoParticipacion::query()
            ->with('ventas')
            ->where('evento_id', $event->id)
            ->where('organizacion_id', $orgId)
            ->where('persona_id', $personaId)
            ->first();
        $ajuste = ClubesCajaMovimiento::ajustesParticipantes($orgId, (int) $event->id)[(int) $event->id][$personaId] ?? 0.0;

        return [
            'evento' => $this->eventPayload($event),
            'servicios' => $servicios,
            'integrante' => $this->mapIntegrante($persona, $row, $servicioIds, null, $actor, (float) $ajuste),
            'recaudo' => $this->selfRecaudo($orgId, (int) $event->id, $personaId, $actor),
            'ganancia' => [
                'capital' => round((float) ($event->capital ?? 0), 2),
                'proyeccion_otros' => $this->proyeccionOtros($orgId, (int) $event->id, $personaId, $servicioIds),
                'reparto' => $this->repartoConfig($event),
                ...$this->capitalVenta($event, $personaId),
            ],
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $ventas
     * @return array<string, mixed>
     */
    public function join(User $actor, Event $event, bool $participa, array $ventas = []): array
    {
        [$orgId, $personaId] = $this->assertCanJoin($actor, $event);
        $this->assertParticipationOpen($event);
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

        return $this->self($actor, $this->refrescarGanancias($event));
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
     * @param  \Illuminate\Support\Collection<int, EventoParticipacionAbono>|null  $abonos
     * @return array<string, mixed>
     */
    private function mapIntegrante(
        Persona $persona,
        ?EventoParticipacion $row,
        array $servicioIds,
        $abonos = null,
        ?User $viewer = null,
        float $ajusteParticipantes = 0.0,
    ): array {
        $ventas = [];
        if ($row) {
            $ventas = $row->ventas
                ->filter(fn (EventoParticipacionVenta $venta) => in_array((int) $venta->producto_servicio_id, $servicioIds, true))
                ->map(fn (EventoParticipacionVenta $venta) => [
                    'producto_servicio_id' => (int) $venta->producto_servicio_id,
                    'cantidad' => (int) $venta->cantidad,
                    'cantidad_entregada' => $venta->cantidad_entregada === null ? null : (int) $venta->cantidad_entregada,
                ])
                ->values()
                ->all();
        }

        $lista = collect($abonos ?? []);
        $abonado = round((float) $lista->sum(fn (EventoParticipacionAbono $abono) => $abono->montoContable()), 2);
        $visibles = $lista->filter(fn (EventoParticipacionAbono $abono) => $abono->visiblePara($viewer));

        return [
            'persona_id' => (int) $persona->id,
            'full_name' => $persona->full_name,
            'identificacion' => $persona->identificacion,
            'foto_url' => $this->photoUrl($persona),
            'participa' => $row ? (bool) $row->participa : null,
            'ventas' => $ventas,
            'abonado' => $abonado,
            'ajuste_participantes' => round($ajusteParticipantes, 2),
            'abonos' => $visibles->map(fn (EventoParticipacionAbono $abono) => $this->presentacion->movimiento($abono))->values()->all(),
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
     * @param  list<int>  $servicioIds
     */
    private function proyeccionOtros(int $orgId, int $eventoId, int $personaId, array $servicioIds): float
    {
        $rows = EventoParticipacion::query()
            ->with('ventas.producto')
            ->where('evento_id', $eventoId)
            ->where('organizacion_id', $orgId)
            ->where('participa', true)
            ->where('persona_id', '!=', $personaId)
            ->get();

        $total = $rows->sum(function (EventoParticipacion $row) use ($servicioIds, $eventoId) {
            return $row->ventas->sum(function (EventoParticipacionVenta $venta) use ($servicioIds, $eventoId) {
                if (! in_array((int) $venta->producto_servicio_id, $servicioIds, true)) {
                    return 0;
                }

                return $venta->cantidadContable() * $this->productos->precioDeOferta(
                    $eventoId,
                    (int) $venta->producto_servicio_id,
                    (float) ($venta->producto?->precio ?? 0),
                );
            });
        });

        return round((float) $total, 2);
    }

    /**
     * @return array<string, mixed>
     */
    private function selfRecaudo(int $orgId, int $eventoId, int $personaId, ?User $viewer = null): array
    {
        $abonos = EventoParticipacionAbono::query()
            ->with('producto')
            ->where('organizacion_id', $orgId)
            ->where('evento_id', $eventoId)
            ->where('persona_id', $personaId)
            ->orderByDesc('created_at')
            ->get();
        $visibles = $abonos->filter(fn (EventoParticipacionAbono $abono) => $abono->visiblePara($viewer));

        $ajuste = ClubesCajaMovimiento::ajustesParticipantes($orgId, $eventoId)[$eventoId][$personaId] ?? 0.0;

        return [
            'abonado' => round((float) $abonos->sum(fn (EventoParticipacionAbono $abono) => $abono->montoContable()), 2),
            'ajuste_participantes' => round((float) $ajuste, 2),
            'abonos' => $visibles->map(fn (EventoParticipacionAbono $abono) => $this->presentacion->movimiento($abono))->values()->all(),
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function eventServices(Event $event): array
    {
        $cantidades = EventoParticipacionVenta::totalesPorProducto(
            (int) $event->id,
            $event->cuentaUnidadesEntregadas(),
        );

        return EventoProductoServicio::query()
            ->where('evento_id', $event->id)
            ->where('activo', true)
            ->with('producto')
            ->get()
            ->map(function (EventoProductoServicio $oferta) use ($cantidades) {
                $producto = $oferta->producto;
                if (! $producto instanceof ProductoServicio) {
                    return null;
                }
                $productoId = (int) $producto->id;

                return [
                    'id' => $productoId,
                    'nombre' => $producto->nombre,
                    'precio' => $oferta->precio,
                    'inversion' => round((float) ($oferta->inversion ?? 0), 2),
                    'cantidad_proyectada' => (int) ($cantidades[$productoId] ?? $oferta->cantidad_proyectada ?? 0),
                    'ganancia_unidad' => round((float) ($oferta->ganancia_unidad ?? 0), 2),
                    'icono' => $producto->icono,
                    'image_url' => $this->publicFiles->url($producto->image_path),
                ];
            })
            ->filter()
            ->values()
            ->all();
    }

    private function refrescarGanancias(Event $event): Event
    {
        $this->ganancias->registrar($event->fresh() ?? $event);

        return $event->fresh() ?? $event;
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

    private function assertParticipationOpen(Event $event): void
    {
        abort_unless(
            ! in_array($event->estado, [Event::ESTADO_EN_PROCESO, Event::ESTADO_CERRADO], true),
            Response::HTTP_UNPROCESSABLE_ENTITY,
            'Esta actividad ya está en curso. Ya no se puede inscribir ni cambiar cantidades.',
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
            ->with('user')
            ->whereIn('id', $personaIds->isEmpty() ? [0] : $personaIds)
            ->orderBy('apellido1')
            ->orderBy('nombre1')
            ->get();
    }

    private function photoUrl(Persona $persona): ?string
    {
        return $this->publicFiles->url($persona->foto)
            ?? $this->publicFiles->url($persona->user?->avatar_url);
    }

    /**
     * Capital de cada producto: inversión dividida entre la cantidad proyectada.
     *
     * @return array{capital_propio: float, unidades: int, capital_unidad: float}
     */
    private function capitalVenta(Event $event, int $personaId): array
    {
        $ofertas = EventoProductoServicio::query()
            ->where('evento_id', $event->id)
            ->where('activo', true)
            ->get()
            ->keyBy('producto_servicio_id');
        $totales = EventoParticipacionVenta::totalesPorProducto(
            (int) $event->id,
            $event->cuentaUnidadesEntregadas(),
        );
        $row = EventoParticipacion::query()
            ->with('ventas')
            ->where('evento_id', $event->id)
            ->where('persona_id', $personaId)
            ->where('participa', true)
            ->first();

        $capital = 0;
        $own = 0;
        $unit = 0;
        foreach ($row?->ventas ?? [] as $venta) {
            $qty = $venta->cantidadContable();
            $own += $qty;
            $productoId = (int) $venta->producto_servicio_id;
            $oferta = $ofertas->get($productoId);
            $proyectada = (int) ($totales[$productoId] ?? $oferta?->cantidad_proyectada ?? 0);
            $inversion = (float) ($oferta?->inversion ?? 0);
            $unitCost = $proyectada > 0 ? (int) round($inversion / $proyectada) : 0;
            $capital += $qty * $unitCost;
            $unit = $unitCost;
        }

        return [
            'capital_propio' => (float) $capital,
            'unidades' => $own,
            'capital_unidad' => $own > 0 && $capital > 0 ? round($capital / $own, 2) : (float) $unit,
        ];
    }

    /**
     * @return array{ofrenda: float, diezmo: float, resto: float, miembros: float, club: float, extras: float}|null
     */
    private function repartoConfig(Event $event): ?array
    {
        $id = (int) ($event->ganancia_eclesiastica_id ?? 0);
        if ($id <= 0) {
            return null;
        }

        $config = ClubesGananciaEclesiastica::query()->with('distribucion')->find($id);
        if (! $config) {
            return null;
        }

        $diezmo = (float) $config->diezmo;
        $ofrenda = (float) $config->ofrenda;
        $dist = $config->distribucion;

        return [
            'ofrenda' => $ofrenda,
            'diezmo' => $diezmo,
            'resto' => round(100 - $diezmo - $ofrenda, 2),
            'miembros' => (float) ($dist->miembros ?? 0),
            'club' => (float) ($dist->club ?? 0),
            'extras' => (float) ($dist->extras ?? 0),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function eventPayload(Event $event): array
    {
        $event->loadMissing(['tipoEvento:id,nombre,slug,color,icono', 'cuentaBancaria.qrFile']);

        return [
            'id' => (int) $event->id,
            'name' => $event->name,
            'descripcion' => $event->descripcion,
            'lugar' => $event->lugar,
            'starts_at' => $event->starts_at?->toIso8601String(),
            'ends_at' => $event->ends_at?->toIso8601String(),
            'estado' => $event->estado,
            'ganancia_eclesiastica_id' => $event->ganancia_eclesiastica_id
                ? (int) $event->ganancia_eclesiastica_id
                : null,
            'capital' => round((float) ($event->capital ?? 0), 2),
            'ganancia_registro' => EventoGananciaRegistro::presentar($event),
            'cuenta_bancaria_id' => $event->cuenta_bancaria_id
                ? (int) $event->cuenta_bancaria_id
                : null,
            'cuenta_bancaria' => $this->cuentas->forAbono(
                $event->cuentaBancaria,
                (int) $event->organizacion_id,
            ),
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
