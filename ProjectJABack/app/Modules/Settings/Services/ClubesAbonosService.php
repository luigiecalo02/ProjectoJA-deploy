<?php

namespace App\Modules\Settings\Services;

use App\Models\User;
use App\Modules\Events\Models\Event;
use App\Modules\Events\Models\EventoParticipacion;
use App\Modules\Events\Models\EventoParticipacionAbono;
use App\Modules\Events\Models\EventoParticipacionVenta;
use App\Modules\Events\Models\TipoEvento;
use App\Modules\Events\Services\ProductoServicioService;
use App\Modules\Clubs\Models\Persona;
use App\Modules\Organizations\Models\PersonaOrganizacion;
use App\Modules\Settings\Models\AppSetting;
use App\Modules\Shared\Services\ImageOptimizer;
use App\Modules\Shared\Services\PublicFileService;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\Response;

final class ClubesAbonosService
{
    public function __construct(
        private readonly PublicFileService $publicFiles,
        private readonly ImageOptimizer $images,
        private readonly AbonoPresentacion $presentacion,
        private readonly ProductoServicioService $productos,
        private readonly ClubesFinanzasService $finanzas,
        private readonly ClubesCuentasBancariasService $cuentas,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function board(User $actor, string $modo, ?int $personaId, ?int $eventoId): array
    {
        $orgId = $this->assertCanView($actor);
        $modo = $modo === 'actividad' ? 'actividad' : 'integrante';
        $ownOnly = ! $actor->hasPermission('abonos.view');

        if ($ownOnly) {
            $ownId = (int) ($actor->persona_id ?? 0);
            abort_unless($ownId > 0, Response::HTTP_UNPROCESSABLE_ENTITY, 'Tu usuario no está vinculado a una persona.');
            abort_unless($personaId === null || $personaId === $ownId, Response::HTTP_FORBIDDEN, 'Solo puedes ver tus abonos.');
            abort_unless($eventoId === null, Response::HTTP_FORBIDDEN, 'Solo puedes ver tus abonos.');
        }

        $participaciones = $this->participaciones($orgId);
        if ($ownOnly) {
            $participaciones = $participaciones
                ->where('persona_id', (int) $actor->persona_id)
                ->values();
        }
        $abonos = $this->abonosPorClave($orgId);

        $opcionesIntegrantes = $this->opcionesIntegrantes($participaciones);
        $opcionesActividades = $this->opcionesActividades($participaciones);

        $filas = $participaciones
            ->when($personaId, fn (Collection $rows) => $rows->where('persona_id', $personaId))
            ->when($eventoId, fn (Collection $rows) => $rows->where('evento_id', $eventoId))
            ->map(function (EventoParticipacion $row) use ($abonos, $modo, $personaId, $eventoId, $actor, $orgId) {
                $clave = $this->clave((int) $row->evento_id, (int) $row->persona_id);
                $lista = $abonos->get($clave, collect());
                $comprometido = $this->comprometido($row);
                $abonado = round((float) $lista->sum(fn (EventoParticipacionAbono $abono) => $abono->montoContable()), 2);
                $ganancia = $this->gananciaReal($row, $comprometido, $abonado);
                $detalle = ($modo === 'integrante' && $personaId) || ($modo === 'actividad' && $eventoId);
                $visibles = $lista->filter(fn (EventoParticipacionAbono $abono) => $abono->visiblePara($actor));

                return [
                    'persona_id' => (int) $row->persona_id,
                    'full_name' => $row->persona?->full_name,
                    'foto_url' => $this->photoUrl($row->persona),
                    'evento_id' => (int) $row->evento_id,
                    'evento_name' => $row->evento?->name,
                    'image_url' => $row->evento?->image_url,
                    'banner_url' => $row->evento?->banner_url,
                    'starts_at' => $row->evento?->starts_at?->toIso8601String(),
                    'evento_estado' => $row->evento?->estado,
                    'comprometido' => $comprometido,
                    'abonado' => $abonado,
                    'pendiente' => round($comprometido - $abonado, 2),
                    'ganancia' => $ganancia,
                    'items' => 1,
                    'pendientes' => ($comprometido - $abonado) > 0.009 ? 1 : 0,
                    'cuenta_bancaria' => $this->cuentas->forAbono($row->evento?->cuentaBancaria, $orgId),
                    'pedidos' => $detalle ? $this->pedidos($row) : [],
                    'abonos' => $detalle
                        ? $visibles->map(fn (EventoParticipacionAbono $abono) => $this->presentacion->movimiento($abono))->values()->all()
                        : [],
                ];
            })
            ->values();

        if ($modo === 'integrante' && ! $personaId) {
            $filas = $this->agruparPorPersona($filas);
        } elseif ($modo === 'actividad' && ! $eventoId) {
            $filas = $this->agruparPorEvento($filas);
        }

        return [
            'modo' => $modo,
            'persona_id' => $personaId,
            'evento_id' => $eventoId,
            'resumen' => $this->resumen($filas),
            'opciones_integrantes' => $opcionesIntegrantes,
            'opciones_actividades' => $opcionesActividades,
            'filas' => $filas->all(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    /**
     * @param  list<array{producto_servicio_id?: mixed, monto?: mixed}>|null  $partidas
     * @return array<string, mixed>
     */
    public function store(
        User $actor,
        int $eventoId,
        int $personaId,
        ?float $monto,
        ?string $nota,
        string $modo = 'integrante',
        ?UploadedFile $comprobante = null,
        ?int $productoServicioId = null,
        ?array $partidas = null,
    ): array {
        $orgId = $this->assertCanUpdate($actor);

        $participacion = EventoParticipacion::query()
            ->with(['evento.tipoEvento', 'ventas.producto'])
            ->where('evento_id', $eventoId)
            ->where('organizacion_id', $orgId)
            ->where('persona_id', $personaId)
            ->where('participa', true)
            ->first();

        if (! $participacion) {
            throw ValidationException::withMessages([
                'persona_id' => ['Esa persona no está marcada como participante de la actividad.'],
            ]);
        }

        $this->assertEventoAbonable($participacion->evento, $actor);
        abort_unless(
            PersonaOrganizacion::query()
                ->where('organizacion_id', $orgId)
                ->where('persona_id', $personaId)
                ->where('estado', true)
                ->exists(),
            Response::HTTP_FORBIDDEN,
            'La persona no es integrante de este club.',
        );

        $lines = $this->normalizePartidas($participacion, $partidas, $monto, $productoServicioId);
        $stored = $comprobante
            ? $this->images->storeCompact($comprobante, 'abonos/'.$orgId, 'pantallazo')
            : null;

        foreach ($lines as $line) {
            $created = EventoParticipacionAbono::query()->create([
                'evento_id' => $eventoId,
                'organizacion_id' => $orgId,
                'persona_id' => $personaId,
                'producto_servicio_id' => $line['producto_servicio_id'],
                'monto' => $line['monto'],
                'nota' => $nota !== null && $nota !== '' ? $nota : null,
                'estado' => EventoParticipacionAbono::ESTADO_APROBADO,
                'comprobante_path' => $stored?->path,
                'registrado_por' => $actor->id,
            ]);
            $this->finanzas->registrarAbonoAprobado($created->loadMissing(['evento', 'producto']));
        }

        return $this->board(
            $actor,
            $modo === 'actividad' ? 'actividad' : 'integrante',
            $modo === 'actividad' ? null : $personaId,
            $modo === 'actividad' ? $eventoId : null,
        );
    }

    /**
     * @param  list<array{producto_servicio_id?: mixed, monto?: mixed}>|null  $partidas
     * @return array{abonado: float, abonos: list<array<string, mixed>>}
     */
    public function submitComprobante(
        User $actor,
        int $eventoId,
        UploadedFile $file,
        ?int $productoServicioId = null,
        ?array $partidas = null,
    ): array {
        abort_unless(
            $actor->hasPermission('mis_abonos.update'),
            Response::HTTP_FORBIDDEN,
            'No puedes enviar el pantallazo del abono.',
        );

        $orgId = AppSetting::resolveOrganizacionId();
        abort_unless($orgId, Response::HTTP_FORBIDDEN, 'Debes tener una organización activa.');
        $personaId = (int) ($actor->persona_id ?? 0);
        abort_unless($personaId > 0, Response::HTTP_UNPROCESSABLE_ENTITY, 'Tu usuario no está vinculado a una persona.');
        abort_unless(
            PersonaOrganizacion::query()
                ->where('organizacion_id', $orgId)
                ->where('persona_id', $personaId)
                ->where('estado', true)
                ->exists(),
            Response::HTTP_FORBIDDEN,
            'Solo los integrantes del club pueden abonar.',
        );

        $participacion = EventoParticipacion::query()
            ->with(['evento.tipoEvento', 'ventas'])
            ->where('evento_id', $eventoId)
            ->where('organizacion_id', $orgId)
            ->where('persona_id', $personaId)
            ->where('participa', true)
            ->first();

        if (! $participacion) {
            throw ValidationException::withMessages([
                'evento_id' => ['Primero tienes que participar en esta actividad.'],
            ]);
        }

        $this->assertEventoAbonable($participacion->evento, $actor);

        $lines = $partidas
            ? $this->normalizePartidas($participacion, $partidas, null, null)
            : [[
                'producto_servicio_id' => $this->assertProductoDeParticipacion($participacion, $productoServicioId),
                'monto' => 0.0,
            ]];
        $stored = $this->images->storeCompact($file, 'abonos/'.$orgId, 'pantallazo');

        foreach ($lines as $line) {
            EventoParticipacionAbono::query()->create([
                'evento_id' => $eventoId,
                'organizacion_id' => $orgId,
                'persona_id' => $personaId,
                'producto_servicio_id' => $line['producto_servicio_id'],
                'monto' => $line['monto'],
                'estado' => EventoParticipacionAbono::ESTADO_PENDIENTE,
                'comprobante_path' => $stored->path,
                'registrado_por' => $actor->id,
            ]);
        }

        return $this->recaudo($orgId, $eventoId, $personaId, $actor);
    }

    /**
     * @param  list<array{producto_servicio_id?: mixed, monto?: mixed}>|null  $partidas
     * @return list<array{producto_servicio_id: int|null, monto: float}>
     */
    private function normalizePartidas(
        EventoParticipacion $participacion,
        ?array $partidas,
        ?float $monto,
        ?int $productoServicioId,
    ): array {
        if (is_array($partidas) && $partidas !== []) {
            $lines = [];
            foreach ($partidas as $index => $partida) {
                $productoId = $this->assertProductoDeParticipacion(
                    $participacion,
                    isset($partida['producto_servicio_id']) ? (int) $partida['producto_servicio_id'] : null,
                );
                $value = round((float) ($partida['monto'] ?? 0), 2);
                if ($productoId === null || $value <= 0) {
                    throw ValidationException::withMessages([
                        "partidas.$index.monto" => ['Indica un valor mayor a cero para cada producto.'],
                    ]);
                }
                $lines[] = [
                    'producto_servicio_id' => $productoId,
                    'monto' => $value,
                ];
            }

            return $lines;
        }

        abort_unless(($monto ?? 0) > 0, Response::HTTP_UNPROCESSABLE_ENTITY, 'El abono debe ser mayor a cero.');

        return [[
            'producto_servicio_id' => $this->assertProductoDeParticipacion($participacion, $productoServicioId),
            'monto' => round((float) $monto, 2),
        ]];
    }

    private function assertProductoDeParticipacion(EventoParticipacion $participacion, ?int $productoServicioId): ?int
    {
        if ($productoServicioId === null || $productoServicioId <= 0) {
            return null;
        }

        $venta = $participacion->ventas
            ->first(fn (EventoParticipacionVenta $row) => (int) $row->producto_servicio_id === $productoServicioId);

        if (! $venta || (int) $venta->cantidad <= 0) {
            throw ValidationException::withMessages([
                'producto_servicio_id' => ['Ese producto no está en el pedido de este participante.'],
            ]);
        }

        return $productoServicioId;
    }

    /**
     * @return array<string, mixed>
     */
    public function updateMonto(User $actor, EventoParticipacionAbono $abono, float $monto): array
    {
        $orgId = $this->assertDirector($actor);
        abort_unless((int) $abono->organizacion_id === $orgId, Response::HTTP_NOT_FOUND);
        $this->assertEventoAbonable($abono->loadMissing(['evento.tipoEvento'])->evento, $actor);
        $estado = $abono->estado ?: EventoParticipacionAbono::ESTADO_APROBADO;
        abort_unless(
            $estado === EventoParticipacionAbono::ESTADO_APROBADO,
            Response::HTTP_UNPROCESSABLE_ENTITY,
            'Solo se cambia el valor de un abono ya aprobado.',
        );

        $abono->monto = round($monto, 2);
        $abono->save();
        $this->finanzas->registrarAbonoAprobado($abono->loadMissing(['evento', 'producto']));

        $recaudo = $this->recaudo((int) $abono->organizacion_id, (int) $abono->evento_id, (int) $abono->persona_id, $actor);

        return [
            'abono' => $this->presentacion->movimiento($abono->fresh() ?? $abono),
            'persona_id' => (int) $abono->persona_id,
            'evento_id' => (int) $abono->evento_id,
            'abonado' => $recaudo['abonado'],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function review(User $actor, EventoParticipacionAbono $abono, string $estado, ?float $monto): array
    {
        if ($estado === EventoParticipacionAbono::ESTADO_ELIMINADO) {
            return $this->eliminar($actor, $abono);
        }

        $orgId = $this->assertCanUpdate($actor);
        abort_unless((int) $abono->organizacion_id === $orgId, Response::HTTP_NOT_FOUND);
        abort_unless(
            $abono->estado === EventoParticipacionAbono::ESTADO_PENDIENTE,
            Response::HTTP_UNPROCESSABLE_ENTITY,
            'Este abono ya fue revisado.',
        );
        if ($estado === EventoParticipacionAbono::ESTADO_APROBADO) {
            $this->assertEventoAbonable($abono->loadMissing(['evento.tipoEvento'])->evento, $actor);
            abort_unless($monto !== null && $monto > 0, Response::HTTP_UNPROCESSABLE_ENTITY, 'Indica el valor del abono.');
            $abono->monto = round($monto, 2);
            $abono->estado = EventoParticipacionAbono::ESTADO_APROBADO;
        } else {
            $abono->estado = EventoParticipacionAbono::ESTADO_RECHAZADO;
        }
        $abono->save();
        if ($abono->estado === EventoParticipacionAbono::ESTADO_APROBADO) {
            $this->finanzas->registrarAbonoAprobado($abono->loadMissing(['evento', 'producto']));
        }

        $recaudo = $this->recaudo((int) $abono->organizacion_id, (int) $abono->evento_id, (int) $abono->persona_id, $actor);

        return [
            'abono' => $this->presentacion->movimiento($abono->fresh() ?? $abono),
            'persona_id' => (int) $abono->persona_id,
            'evento_id' => (int) $abono->evento_id,
            'abonado' => $recaudo['abonado'],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function eliminar(User $actor, EventoParticipacionAbono $abono): array
    {
        $orgId = $this->assertDirector($actor);
        abort_unless((int) $abono->organizacion_id === $orgId, Response::HTTP_NOT_FOUND);
        $estado = $abono->estado ?: EventoParticipacionAbono::ESTADO_APROBADO;
        abort_unless(
            $estado === EventoParticipacionAbono::ESTADO_APROBADO,
            Response::HTTP_UNPROCESSABLE_ENTITY,
            'Solo se eliminan abonos ya aprobados.',
        );

        $abono->estado = EventoParticipacionAbono::ESTADO_ELIMINADO;
        $abono->save();
        $this->finanzas->anularAbono($abono->loadMissing(['evento', 'producto']));

        $recaudo = $this->recaudo((int) $abono->organizacion_id, (int) $abono->evento_id, (int) $abono->persona_id, $actor);

        return [
            'abono' => $this->presentacion->movimiento($abono->fresh() ?? $abono),
            'persona_id' => (int) $abono->persona_id,
            'evento_id' => (int) $abono->evento_id,
            'abonado' => $recaudo['abonado'],
        ];
    }

    /**
     * @return array{abonado: float, abonos: list<array<string, mixed>>}
     */
    private function recaudo(int $orgId, int $eventoId, int $personaId, ?User $viewer = null): array
    {
        $abonos = EventoParticipacionAbono::query()
            ->with('producto')
            ->where('organizacion_id', $orgId)
            ->where('evento_id', $eventoId)
            ->where('persona_id', $personaId)
            ->orderByDesc('created_at')
            ->get();

        $visibles = $abonos->filter(fn (EventoParticipacionAbono $abono) => $abono->visiblePara($viewer));

        return [
            'abonado' => round((float) $abonos->sum(fn (EventoParticipacionAbono $abono) => $abono->montoContable()), 2),
            'abonos' => $visibles->map(fn (EventoParticipacionAbono $abono) => $this->presentacion->movimiento($abono))->values()->all(),
        ];
    }

    /**
     * @return Collection<int, EventoParticipacion>
     */
    private function participaciones(int $orgId): Collection
    {
        return EventoParticipacion::query()
            ->with(['persona.user', 'evento.tipoEvento', 'evento.cuentaBancaria.qrFile', 'ventas.producto'])
            ->where('organizacion_id', $orgId)
            ->where('participa', true)
            ->whereHas('evento', function ($query) {
                $query->whereNull('evento_padre_id')
                    ->whereRaw('LOWER(estado) != ?', [Event::ESTADO_CANCELADO])
                    ->whereHas(
                        'tipoEvento',
                        fn ($tipo) => $tipo->where('slug', TipoEvento::SLUG_ACTIVIDAD_ECONOMICA),
                    );
            })
            ->get()
            ->filter(function (EventoParticipacion $row) {
                $event = $row->evento;

                return $event instanceof Event
                    && strcasecmp((string) $event->estado, Event::ESTADO_CANCELADO) !== 0;
            })
            ->values();
    }

    private function assertEventoAbonable(?Event $event, User $actor): void
    {
        abort_unless($event instanceof Event, Response::HTTP_NOT_FOUND);
        abort_unless(
            strcasecmp((string) $event->estado, Event::ESTADO_CANCELADO) !== 0
                && $event->isVisibleTo($actor)
                && $event->isActividadEconomica(),
            Response::HTTP_UNPROCESSABLE_ENTITY,
            'Esta actividad está cancelada. No se registra en abonos ni en caja.',
        );
    }

    /**
     * @return Collection<string, Collection<int, EventoParticipacionAbono>>
     */
    private function abonosPorClave(int $orgId): Collection
    {
        return EventoParticipacionAbono::query()
            ->with('producto')
            ->where('organizacion_id', $orgId)
            ->orderByDesc('created_at')
            ->get()
            ->groupBy(fn (EventoParticipacionAbono $row) => $this->clave((int) $row->evento_id, (int) $row->persona_id));
    }

    private function precioDeVenta(EventoParticipacionVenta $venta, int $eventoId): float
    {
        return $this->productos->precioDeOferta(
            $eventoId,
            (int) $venta->producto_servicio_id,
            (float) ($venta->producto?->precio ?? 0),
        );
    }

    private function comprometido(EventoParticipacion $row): float
    {
        return round((float) $row->ventas->sum(
            fn (EventoParticipacionVenta $venta) => $venta->cantidadContable() * $this->precioDeVenta($venta, (int) $row->evento_id),
        ), 2);
    }

    private function gananciaReal(EventoParticipacion $row, float $comprometido, float $abonado): float
    {
        $base = (float) $row->ventas->sum(function (EventoParticipacionVenta $venta) use ($row) {
            $unidad = $this->productos->gananciaUnidadDeOferta((int) $row->evento_id, (int) $venta->producto_servicio_id);

            return $venta->cantidadContable() * $unidad;
        });
        if ($comprometido <= 0 || $base <= 0) {
            return 0;
        }
        $cobertura = min($comprometido, max(0, $abonado)) / $comprometido;

        return (float) round($base * $cobertura);
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function pedidos(EventoParticipacion $row): array
    {
        return $row->ventas
            ->filter(fn (EventoParticipacionVenta $venta) => (int) $venta->cantidad > 0)
            ->map(function (EventoParticipacionVenta $venta) use ($row) {
                $cantidad = (int) $venta->cantidad;
                $precio = $this->precioDeVenta($venta, (int) $row->evento_id);

                return [
                    'id' => (int) $venta->id,
                    'nombre' => $venta->producto?->nombre ?? 'Servicio',
                    'cantidad' => $cantidad,
                    'precio' => $precio,
                    'total' => round($cantidad * $precio, 2),
                    'icono' => $venta->producto?->icono,
                    'image_url' => $this->publicFiles->url($venta->producto?->image_path),
                ];
            })
            ->values()
            ->all();
    }

    /**
     * @param  Collection<int, array<string, mixed>>  $filas
     * @return Collection<int, array<string, mixed>>
     */
    private function agruparPorPersona(Collection $filas): Collection
    {
        return $filas->groupBy('persona_id')->map(function (Collection $rows) {
            $first = $rows->first();
            $comprometido = round((float) $rows->sum('comprometido'), 2);
            $abonado = round((float) $rows->sum('abonado'), 2);
            $ganancia = round((float) $rows->sum('ganancia'), 2);

            return [
                'persona_id' => $first['persona_id'],
                'full_name' => $first['full_name'],
                'foto_url' => $first['foto_url'] ?? null,
                'evento_id' => null,
                'evento_name' => $rows->count().' '.($rows->count() === 1 ? 'actividad' : 'actividades'),
                'image_url' => null,
                'starts_at' => null,
                'comprometido' => $comprometido,
                'abonado' => $abonado,
                'pendiente' => round($comprometido - $abonado, 2),
                'ganancia' => $ganancia,
                'items' => $rows->count(),
                'pendientes' => $rows->where('pendiente', '>', 0)->count(),
                'abonos' => [],
            ];
        })->sortBy('full_name', SORT_NATURAL | SORT_FLAG_CASE)->values();
    }

    /**
     * @param  Collection<int, array<string, mixed>>  $filas
     * @return Collection<int, array<string, mixed>>
     */
    private function agruparPorEvento(Collection $filas): Collection
    {
        return $filas->groupBy('evento_id')->map(function (Collection $rows) {
            $first = $rows->first();
            $comprometido = round((float) $rows->sum('comprometido'), 2);
            $abonado = round((float) $rows->sum('abonado'), 2);
            $ganancia = round((float) $rows->sum('ganancia'), 2);

            return [
                'persona_id' => null,
                'full_name' => $rows->count().' '.($rows->count() === 1 ? 'integrante' : 'integrantes'),
                'foto_url' => null,
                'evento_id' => $first['evento_id'],
                'evento_name' => $first['evento_name'],
                'image_url' => $first['image_url'] ?? null,
                'banner_url' => $first['banner_url'] ?? null,
                'starts_at' => $first['starts_at'],
                'evento_estado' => $first['evento_estado'] ?? null,
                'comprometido' => $comprometido,
                'abonado' => $abonado,
                'pendiente' => round($comprometido - $abonado, 2),
                'ganancia' => $ganancia,
                'items' => $rows->count(),
                'pendientes' => $rows->where('pendiente', '>', 0)->count(),
                'cuenta_bancaria' => $first['cuenta_bancaria'] ?? null,
                'abonos' => [],
            ];
        })->sortByDesc('starts_at')->values();
    }

    /**
     * @param  Collection<int, EventoParticipacion>  $participaciones
     * @return list<array<string, mixed>>
     */
    private function opcionesIntegrantes(Collection $participaciones): array
    {
        return $participaciones
            ->unique('persona_id')
            ->map(fn (EventoParticipacion $row) => [
                'id' => (int) $row->persona_id,
                'nombre' => $row->persona?->full_name ?? 'Integrante',
                'foto_url' => $this->photoUrl($row->persona),
            ])
            ->sortBy('nombre', SORT_NATURAL | SORT_FLAG_CASE)
            ->values()
            ->all();
    }

    /**
     * @param  Collection<int, EventoParticipacion>  $participaciones
     * @return list<array<string, mixed>>
     */
    private function opcionesActividades(Collection $participaciones): array
    {
        return $participaciones
            ->unique('evento_id')
            ->map(fn (EventoParticipacion $row) => [
                'id' => (int) $row->evento_id,
                'nombre' => $row->evento?->name ?? 'Actividad',
                'starts_at' => $row->evento?->starts_at?->toIso8601String(),
                'image_url' => $row->evento?->image_url,
            ])
            ->sortByDesc('starts_at')
            ->values()
            ->all();
    }

    /**
     * @param  Collection<int, array<string, mixed>>  $filas
     * @return array<string, float>
     */
    private function resumen(Collection $filas): array
    {
        $comprometido = round((float) $filas->sum('comprometido'), 2);
        $abonado = round((float) $filas->sum('abonado'), 2);

        return [
            'comprometido' => $comprometido,
            'abonado' => $abonado,
            'pendiente' => round($comprometido - $abonado, 2),
            'ganancia' => round((float) $filas->sum('ganancia'), 2),
        ];
    }

    private function clave(int $eventoId, int $personaId): string
    {
        return $eventoId.':'.$personaId;
    }

    private function photoUrl(?Persona $persona): ?string
    {
        if (! $persona) {
            return null;
        }

        return $this->publicFiles->url($persona->foto)
            ?? $this->publicFiles->url($persona->user?->avatar_url);
    }

    private function assertCanView(User $actor): int
    {
        $orgId = AppSetting::resolveOrganizacionId();
        abort_unless($orgId, Response::HTTP_FORBIDDEN, 'Debes tener una organización activa.');
        abort_unless(
            $actor->hasPermission('abonos.view') || $actor->hasPermission('mis_abonos.view'),
            Response::HTTP_FORBIDDEN,
            'No puedes ver los abonos del club.',
        );

        return $orgId;
    }

    private function assertDirector(User $actor): int
    {
        $orgId = $this->assertCanView($actor);
        abort_unless(
            $actor->isSuperAdmin() || in_array('director', $actor->roleNames(), true),
            Response::HTTP_FORBIDDEN,
            'Solo el director puede cambiar o eliminar el abono.',
        );

        return $orgId;
    }

    private function assertCanUpdate(User $actor): int
    {
        $orgId = $this->assertCanView($actor);
        abort_unless(
            $this->actorManagesAbonos($actor) || $actor->hasPermission('abonos.update'),
            Response::HTTP_FORBIDDEN,
            'No puedes registrar abonos.',
        );

        return $orgId;
    }

    private function actorManagesAbonos(User $actor): bool
    {
        return count(array_intersect($actor->roleNames(), [
            'director',
            'subdirector',
            'secretario',
            'tesorero',
        ])) > 0;
    }
}
