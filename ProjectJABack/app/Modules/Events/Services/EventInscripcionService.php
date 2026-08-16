<?php

namespace App\Modules\Events\Services;

use App\Models\User;
use App\Modules\Clubs\Models\Club;
use App\Modules\Clubs\Models\Persona;
use App\Modules\Clubs\Services\ClubService;
use App\Modules\Events\Models\Event;
use App\Modules\Events\Models\EventoInscripcion;
use App\Modules\Events\Models\EventoInscripcionComprobante;
use App\Modules\Events\Models\EventoInscripcionComprobanteComentario;
use App\Modules\Events\Models\EventoInscripcionPersona;
use App\Modules\Events\Models\EventoPago;
use App\Modules\Events\Models\EventoProductoServicio;
use App\Modules\Events\Models\EventoServicioReserva;
use App\Modules\Events\Models\Seguro;
use App\Modules\Cabanas\Services\AsignacionCamaService;
use App\Modules\Events\Models\TipoSeguro;
use App\Modules\Organizations\Models\PersonaOrganizacion;
use App\Modules\Shared\Models\StoredFile;
use Carbon\Carbon;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

final class EventInscripcionService
{
    public function __construct(
        private readonly EventParticipationService $participation,
        private readonly SeguroService $seguroService,
        private readonly EventInscripcionHistorialService $historialService,
        private readonly ClubService $clubService,
        private readonly AsignacionCamaService $asignacionesCama,
    ) {}

    /** @param array<string, mixed> $data */
    public function enrollClubWithRoster(User $actor, Event $event, array $data): EventoInscripcion
    {
        $ctx = $this->participation->assertClubDirectorContext($actor);
        $root = $this->resolveRoot($event);
        while ($root->evento_padre_id) {
            $root = Event::query()->findOrFail($root->evento_padre_id);
        }

        if (! $actor->can('view', $root)) {
            throw new AccessDeniedHttpException('No puedes ver este evento.');
        }
        if ($root->estado === Event::ESTADO_CANCELADO) {
            throw ValidationException::withMessages(['evento' => ['Este evento está cancelado.']]);
        }
        if (! $root->permite_inscripcion_club) {
            throw ValidationException::withMessages(['evento' => ['Este evento no permite inscripción de clubes.']]);
        }
        if ($root->starts_at && now()->greaterThan($root->starts_at->copy()->endOfDay())) {
            throw ValidationException::withMessages([
                'evento' => ['Las inscripciones cerraron al finalizar el día de inicio del evento.'],
            ]);
        }

        $participantes = $this->normalizarParticipantes($data);
        $personaIds = array_values(array_unique(array_map(
            'intval',
            array_filter(array_column($participantes, 'persona_id'))
        )));
        $miembroIds = array_values(array_unique(array_map(
            'intval',
            array_filter(array_column(array_filter(
                $participantes,
                fn (array $row) => in_array($row['tipo'], [
                    EventoInscripcionPersona::TIPO_MIEMBRO,
                    EventoInscripcionPersona::TIPO_DIRECTIVA,
                ], true)
            ), 'persona_id'))
        )));
        if ($personaIds === []) {
            $tieneAcompanantes = collect($participantes)->contains(
                fn (array $row) => in_array($row['tipo'], [
                    EventoInscripcionPersona::TIPO_ACOMPANANTE,
                    EventoInscripcionPersona::TIPO_ACOMPANANTE_MENOR,
                    EventoInscripcionPersona::TIPO_VISITANTE_PASADIA,
                ], true)
            );
            if (! $tieneAcompanantes) {
                throw ValidationException::withMessages([
                    'participantes' => ['Debes seleccionar al menos un participante.'],
                ]);
            }
        }

        if ($miembroIds !== []) {
            $this->assertPersonasBelongToClub($miembroIds, $ctx['organizacion_id']);
        }
        $visitanteIds = array_values(array_unique(array_map(
            'intval',
            array_filter(array_column(array_filter(
                $participantes,
                fn (array $row) => $row['tipo'] === EventoInscripcionPersona::TIPO_VISITANTE_PASADIA
            ), 'persona_id'))
        )));
        if (
            $visitanteIds !== []
            && PersonaOrganizacion::query()
                ->where('organizacion_id', $ctx['organizacion_id'])
                ->where('estado', true)
                ->whereIn('persona_id', $visitanteIds)
                ->exists()
        ) {
            throw ValidationException::withMessages([
                'participantes' => ['El pasadía solo aplica a personas que no pertenecen al club.'],
            ]);
        }

        return DB::transaction(function () use ($actor, $root, $ctx, $participantes, $data) {
            $existing = $this->participation->findRootInscripcion($root, $ctx['organizacion_id']);
            $snapshotAnterior = $existing
                ? $this->historialService->snapshot($existing)
                : ['total' => 0.0, 'participantes' => []];
            $participantesAnteriores = collect($snapshotAnterior['participantes'] ?? [])
                ->keyBy('ref');

            $inscripcion = EventoInscripcion::query()->updateOrCreate(
                [
                    'evento_id' => $root->id,
                    'tipo' => 'club',
                    'organizacion_id' => $ctx['organizacion_id'],
                ],
                [
                    'persona_id' => null,
                    'estado' => EventoInscripcion::ESTADO_PENDIENTE_REVISION,
                    'inscrito_por' => $actor->id,
                ]
            );

            $this->limpiarDetallePendiente($inscripcion);

            $totalInscripcion = 0.0;
            $totalSeguros = 0.0;
            $lineasPorReferencia = [];

            foreach ($participantes as $row) {
                $personaId = isset($row['persona_id']) ? (int) $row['persona_id'] : null;
                $persona = $personaId ? Persona::query()->findOrFail($personaId) : null;
                $participanteAnterior = $participantesAnteriores->get($row['ref']);
                $descuentoSolicitado = $row['descuento_codigo']
                    ?? $this->resolverCodigoDescuentoCargo(
                        $root,
                        $row['cargo_directiva'] ?? null,
                    );
                [$descuentoCodigo, $descuentoNombre, $descuentoPorcentaje] =
                    $this->resolverDescuento($root, $descuentoSolicitado);
                $valorBase = $this->resolverPrecioBase($root, (string) $row['tipo']);
                $valorDescuento = round($valorBase * ($descuentoPorcentaje / 100), 2);
                $valorInscripcion = round(max(0, $valorBase - $valorDescuento), 2);
                $valorSeguro = 0.0;

                $linea = EventoInscripcionPersona::query()->create([
                    'inscripcion_id' => $inscripcion->id,
                    'persona_id' => $personaId,
                    'tipo' => $row['tipo'],
                    'cargo_directiva' => $row['cargo_directiva'] ?? null,
                    'referencia_cliente' => $row['ref'],
                    'nombre_snapshot' => $persona?->full_name ?? $row['nombre'],
                    'identificacion_snapshot' => $persona?->identificacion ?? ($row['identificacion'] ?? null),
                    'fecha_nacimiento_snapshot' => $persona?->fecha_nacimiento?->toDateString()
                        ?? ($row['fecha_nacimiento'] ?? null),
                    'parentesco' => $row['parentesco'] ?? null,
                    'descuento_codigo' => $descuentoCodigo,
                    'descuento_nombre' => $descuentoNombre,
                    'descuento_porcentaje' => $descuentoPorcentaje,
                    'valor_base' => $valorBase,
                    'valor_descuento' => $valorDescuento,
                    'valor_inscripcion' => $valorInscripcion,
                    'valor_seguro' => 0,
                    'estado' => EventoInscripcionPersona::ESTADO_CONFIRMADA,
                ]);
                $lineasPorReferencia[$row['ref']] = $linea;
                $totalInscripcion += $valorInscripcion;

                if ($root->requiere_seguro && $personaId) {
                    $cobertura = $this->seguroService->estaCubierta($personaId, $root);
                    if (! $cobertura['cubierta']) {
                        $seguro = $this->crearSeguroEvento($personaId, $root, $inscripcion->id);
                        $valorSeguro = (float) $seguro->valor;
                        EventoPago::query()->updateOrCreate(
                            [
                                'pagable_type' => Seguro::class,
                                'pagable_id' => $seguro->id,
                            ],
                            [
                                'inscripcion_id' => $inscripcion->id,
                                'monto' => $seguro->valor,
                                'moneda' => 'COP',
                                'estado' => EventoPago::ESTADO_PENDIENTE,
                            ]
                        );
                    } elseif (
                        (int) ($cobertura['seguro']?->inscripcion_id ?? 0) === (int) $inscripcion->id
                    ) {
                        $valorSeguro = (float) (
                            ($participanteAnterior['valor_seguro'] ?? null)
                                ?? $cobertura['seguro']?->valor
                                ?? 0
                        );
                    }
                } elseif ($root->requiere_seguro) {
                    // El acompañante aún no es una Persona canónica; se congela el costo
                    // dentro de su línea de inscripción.
                    $valorSeguro = (float) ($root->seguro_valor ?? 0);
                }

                $linea->update(['valor_seguro' => $valorSeguro]);
                $totalSeguros += $valorSeguro;

                EventoPago::query()->create([
                    'pagable_type' => EventoInscripcionPersona::class,
                    'pagable_id' => $linea->id,
                    'inscripcion_id' => $inscripcion->id,
                    'monto' => $valorInscripcion + ($personaId ? 0 : $valorSeguro),
                    'moneda' => 'COP',
                    'estado' => EventoPago::ESTADO_PENDIENTE,
                ]);
            }

            $totalReservas = $this->syncReservas(
                $inscripcion,
                $root,
                $data['reservas'] ?? [],
                $lineasPorReferencia
            );

            $inscripcion->update([
                'total_declarado' => $totalInscripcion + $totalSeguros + $totalReservas,
                'estado' => EventoInscripcion::ESTADO_PENDIENTE_REVISION,
            ]);

            $this->participation->applyInscripcionScore($root, $ctx['organizacion_id']);

            $actualizada = $inscripcion->fresh([
                'personas.persona',
                'personas.reservas.oferta.producto',
                'comprobantes.archivo',
                'comprobantes.comentarios.autor',
                'seguros',
                'reservas',
            ]);
            $this->historialService->registrarCambio($actualizada, $snapshotAnterior, $actor);

            return $actualizada->fresh([
                'personas.persona',
                'personas.reservas.oferta.producto',
                'comprobantes.archivo',
                'comprobantes.comentarios.autor',
                'seguros',
                'reservas',
                'movimientos.comprobantes.archivo',
                'movimientos.comprobantes.comentarios.autor',
            ]);
        });
    }

    public function coberturaRoster(User $actor, Event $event): array
    {
        $ctx = $this->participation->assertClubDirectorContext($actor);
        $root = $this->resolveRoot($event);
        $personaIds = PersonaOrganizacion::query()
            ->where('organizacion_id', $ctx['organizacion_id'])
            ->where('estado', true)
            ->pluck('persona_id')
            ->map(fn ($id) => (int) $id)
            ->all();

        $personas = Persona::query()->whereIn('id', $personaIds)->orderBy('apellido1')->orderBy('nombre1')->get();
        $batch = $this->seguroService->coberturaBatch($personaIds, $root);
        $club = Club::query()->where('organizacion_id', $ctx['organizacion_id'])->first();
        $cargosPorPersona = collect($club ? $this->clubService->boardAssignments($club) : [])
            ->filter(fn (array $assignment) => ! empty($assignment['persona_id']))
            ->mapWithKeys(fn (array $assignment) => [
                (int) $assignment['persona_id'] => $assignment['ministry'] === Club::BOARD_SECRETARIA
                    ? EventoInscripcionPersona::CARGO_SECRETARIO
                    : $assignment['ministry'],
            ]);

        return [
            'evento' => [
                'id' => $root->id,
                'name' => $root->name,
                'requiere_pago' => (bool) $root->requiere_pago,
                'precio' => $root->precio,
                'precio_fuera_tiempo' => $root->precio_fuera_tiempo,
                'precio_acompanante' => $root->precio_acompanante,
                'precio_acompanante_fuera_tiempo' => $root->precio_acompanante_fuera_tiempo,
                'precio_acompanante_menor' => $root->precio_acompanante_menor,
                'precio_acompanante_menor_fuera_tiempo' => $root->precio_acompanante_menor_fuera_tiempo,
                'precio_directiva' => $root->precio_directiva,
                'precio_directiva_fuera_tiempo' => $root->precio_directiva_fuera_tiempo,
                'fecha_limite_inscripcion' => $root->fecha_limite_inscripcion?->toIso8601String(),
                'inscripcion_fuera_tiempo' => $root->estaFueraDeFechaLimiteInscripcion(),
                'descuentos_directiva' => $root->descuentos_directiva ?? [],
                'requiere_seguro' => (bool) $root->requiere_seguro,
                'seguro_valor' => $root->seguro_valor,
            ],
            'miembros' => $personas->map(function (Persona $p) use ($batch, $cargosPorPersona) {
                $c = $batch[(int) $p->id] ?? ['cubierta' => false, 'estado' => 'SIN_SEGURO', 'motivo' => null];

                return [
                    'id' => $p->id,
                    'nombre' => trim("{$p->nombre1} {$p->nombre2} {$p->apellido1} {$p->apellido2}"),
                    'identificacion' => $p->identificacion,
                    'fecha_nacimiento' => $p->fecha_nacimiento?->toDateString(),
                    'cargo_directiva' => $cargosPorPersona->get((int) $p->id),
                    'cobertura' => $c,
                ];
            })->values()->all(),
        ];
    }

    public function addComprobante(
        User $actor,
        EventoInscripcion $inscripcion,
        UploadedFile $archivo,
        float $valor,
        ?int $movimientoId = null,
    ): EventoInscripcionComprobante {
        $ctx = $this->participation->assertClubDirectorContext($actor);
        if ((int) $inscripcion->organizacion_id !== $ctx['organizacion_id']) {
            throw new AccessDeniedHttpException('No puedes modificar esta inscripción.');
        }
        if ($inscripcion->estaAprobada()) {
            throw ValidationException::withMessages([
                'inscripcion' => ['La inscripción ya está aprobada.'],
            ]);
        }
        $movimiento = $movimientoId
            ? $inscripcion->movimientos()->find($movimientoId)
            : $inscripcion->movimientos()
                ->with('comprobantes')
                ->orderByDesc('numero')
                ->get()
                ->first(fn ($item) => $item->resumenComprobantes()['saldo_por_soportar'] > 0);
        if ($movimientoId && ! $movimiento) {
            throw ValidationException::withMessages([
                'movimiento_id' => ['La modificación seleccionada no pertenece a esta inscripción.'],
            ]);
        }

        $file = $this->storeComprobanteFile($archivo, $inscripcion->id, $actor->id);

        if ($inscripcion->estado === EventoInscripcion::ESTADO_NO_APROBADA) {
            $inscripcion->update(['estado' => EventoInscripcion::ESTADO_PENDIENTE_REVISION]);
        }

        return EventoInscripcionComprobante::query()->create([
            'inscripcion_id' => $inscripcion->id,
            'movimiento_id' => $movimiento?->id,
            'file_id' => $file->id,
            'valor' => $valor,
            'estado' => EventoInscripcionComprobante::ESTADO_PENDIENTE,
            'subido_por' => $actor->id,
        ])->load(['archivo', 'movimiento']);
    }

    public function replaceComprobante(
        User $actor,
        EventoInscripcionComprobante $comprobante,
        UploadedFile $archivo,
        float $valor,
    ): EventoInscripcionComprobante {
        $comprobante->loadMissing(['inscripcion', 'archivo', 'movimiento']);
        $ctx = $this->participation->assertClubDirectorContext($actor);
        $inscripcion = $comprobante->inscripcion;
        if ((int) $inscripcion->organizacion_id !== $ctx['organizacion_id']) {
            throw new AccessDeniedHttpException('No puedes modificar este comprobante.');
        }
        if ($comprobante->estado === EventoInscripcionComprobante::ESTADO_APROBADO) {
            throw ValidationException::withMessages([
                'comprobante' => ['Un comprobante aprobado debe ser rechazado por el supervisor antes de reemplazarlo.'],
            ]);
        }

        $oldFile = $comprobante->archivo;
        $newFile = $this->storeComprobanteFile($archivo, $inscripcion->id, $actor->id);

        $comprobante->update([
            'file_id' => $newFile->id,
            'valor' => $valor,
            'estado' => EventoInscripcionComprobante::ESTADO_PENDIENTE,
            'observacion' => null,
            'revisado_por' => null,
            'revisado_at' => null,
        ]);
        if ($inscripcion->estado === EventoInscripcion::ESTADO_NO_APROBADA) {
            $inscripcion->update(['estado' => EventoInscripcion::ESTADO_PENDIENTE_REVISION]);
        }

        if ($oldFile) {
            Storage::disk('public')->delete($oldFile->path);
            $oldFile->delete();
        }

        return $comprobante->fresh(['archivo', 'movimiento', 'comentarios.autor']);
    }

    public function addComprobanteComentario(
        User $actor,
        EventoInscripcionComprobante $comprobante,
        string $mensaje,
    ): EventoInscripcionComprobanteComentario {
        $comprobante->loadMissing('inscripcion.evento');
        $inscripcion = $comprobante->inscripcion;
        $evento = $inscripcion?->evento;
        if (! $inscripcion || ! $evento) {
            throw ValidationException::withMessages([
                'comprobante' => ['El comprobante no tiene una inscripción válida.'],
            ]);
        }

        try {
            $this->assertEventSupervisor($actor, $evento);
            $autorTipo = EventoInscripcionComprobanteComentario::AUTOR_SUPERVISOR;
        } catch (AccessDeniedHttpException) {
            $ctx = $this->participation->assertClubDirectorContext($actor);
            if ((int) $inscripcion->organizacion_id !== $ctx['organizacion_id']) {
                throw new AccessDeniedHttpException('No puedes comentar este comprobante.');
            }
            $autorTipo = EventoInscripcionComprobanteComentario::AUTOR_DIRECTOR;
        }

        return EventoInscripcionComprobanteComentario::query()
            ->create([
                'comprobante_id' => $comprobante->id,
                'autor_id' => $actor->id,
                'autor_tipo' => $autorTipo,
                'mensaje' => trim($mensaje),
            ])
            ->load('autor:id,name');
    }

    public function deleteComprobante(User $actor, EventoInscripcionComprobante $comprobante): void
    {
        $ctx = $this->participation->assertClubDirectorContext($actor);
        $inscripcion = $comprobante->inscripcion;
        if ((int) $inscripcion->organizacion_id !== $ctx['organizacion_id']) {
            throw new AccessDeniedHttpException('No puedes eliminar este comprobante.');
        }
        if ($inscripcion->estaAprobada()) {
            throw ValidationException::withMessages(['comprobante' => ['No se puede eliminar: inscripción aprobada.']]);
        }
        if ($comprobante->estado !== EventoInscripcionComprobante::ESTADO_PENDIENTE) {
            throw ValidationException::withMessages(['comprobante' => ['Solo se eliminan comprobantes pendientes.']]);
        }
        $comprobante->delete();
    }

    public function reviewComprobante(
        User $actor,
        EventoInscripcionComprobante $comprobante,
        string $estado,
        ?string $observacion = null,
    ): EventoInscripcionComprobante {
        $comprobante->loadMissing('inscripcion.evento');
        $evento = $comprobante->inscripcion?->evento;
        if (! $evento) {
            throw ValidationException::withMessages(['comprobante' => ['Inscripción sin evento.']]);
        }
        $this->assertEventSupervisor($actor, $evento);
        if (! in_array($estado, [
            EventoInscripcionComprobante::ESTADO_PENDIENTE,
            EventoInscripcionComprobante::ESTADO_APROBADO,
            EventoInscripcionComprobante::ESTADO_RECHAZADO,
        ], true)) {
            throw ValidationException::withMessages(['estado' => ['Estado inválido.']]);
        }

        $inscripcion = $comprobante->inscripcion;
        if ($inscripcion->estado === EventoInscripcion::ESTADO_PENDIENTE_REVISION) {
            $inscripcion->update(['estado' => EventoInscripcion::ESTADO_EN_REVISION]);
        }

        $comprobante->update([
            'estado' => $estado,
            'observacion' => $observacion,
            'revisado_por' => $actor->id,
            'revisado_at' => now(),
        ]);

        if ($estado === EventoInscripcionComprobante::ESTADO_APROBADO) {
            $this->seguroService->activarPendientesSiInscripcionPagada($inscripcion);
        }

        return $comprobante->fresh(['archivo', 'revisadoPor', 'comentarios.autor']);
    }

    public function reviewInscripcion(
        User $actor,
        EventoInscripcion $inscripcion,
        string $estado,
        ?string $observacion = null,
    ): EventoInscripcion {
        $evento = Event::query()->findOrFail($inscripcion->evento_id);
        $this->assertEventSupervisor($actor, $evento);

        if (! in_array($estado, [
            EventoInscripcion::ESTADO_PENDIENTE_REVISION,
            EventoInscripcion::ESTADO_EN_REVISION,
            EventoInscripcion::ESTADO_APROBADA,
            EventoInscripcion::ESTADO_NO_APROBADA,
        ], true)) {
            throw ValidationException::withMessages(['estado' => ['Estado inválido.']]);
        }

        $totalAprobado = $estado === EventoInscripcion::ESTADO_APROBADA
            ? (float) $inscripcion->comprobantes()
                ->where('estado', EventoInscripcionComprobante::ESTADO_APROBADO)
                ->sum('valor')
            : 0.0;

        if (
            $estado === EventoInscripcion::ESTADO_APROBADA
            && $totalAprobado < (float) ($inscripcion->total_declarado ?? 0)
        ) {
            throw ValidationException::withMessages([
                'estado' => ['El valor de los comprobantes aprobados debe cubrir el total de la inscripción.'],
            ]);
        }

        $inscripcion->update([
            'estado' => $estado,
            'observacion_revision' => $observacion,
            'revisado_por' => $actor->id,
            'revisado_at' => now(),
        ]);

        if ($estado === EventoInscripcion::ESTADO_APROBADA) {
            Seguro::query()
                ->where('inscripcion_id', $inscripcion->id)
                ->where('estado', Seguro::ESTADO_PENDIENTE)
                ->update([
                    'estado' => Seguro::ESTADO_ACTIVO,
                    'fecha_pago' => now(),
                ]);
            EventoPago::query()
                ->where('inscripcion_id', $inscripcion->id)
                ->where('estado', EventoPago::ESTADO_PENDIENTE)
                ->update([
                    'estado' => EventoPago::ESTADO_PAGADO,
                    'pagado_at' => now(),
                ]);
            EventoServicioReserva::query()
                ->where('inscripcion_id', $inscripcion->id)
                ->where('estado', EventoServicioReserva::ESTADO_RESERVADA)
                ->whereHas('oferta.producto', fn ($query) => $query->where('tipo', 'CABANA'))
                ->update(['estado' => EventoServicioReserva::ESTADO_CONFIRMADA]);
        }

        if ($estado === EventoInscripcion::ESTADO_NO_APROBADA) {
            $this->asignacionesCama->releaseByInscripcion($inscripcion);
        }

        return $inscripcion->fresh([
            'comprobantes.archivo',
            'comprobantes.comentarios.autor',
            'personas.persona',
            'revisadoPor',
        ]);
    }

    /**
     * @return list<EventoInscripcion>
     */
    public function listInscripcionesParaRevision(User $actor, Event $event): array
    {
        $root = $this->resolveRoot($event);
        $this->assertEventSupervisor($actor, $root);

        return EventoInscripcion::query()
            ->withMin('comprobantes as primera_evidencia_at', 'created_at')
            ->with([
                'organizacion.club',
                'comprobantes.archivo',
                'comprobantes.comentarios.autor',
                'movimientos.comprobantes.archivo',
                'movimientos.comprobantes.comentarios.autor',
                'personas.persona',
                'personas.reservas.oferta.producto',
                'personas.asignacionesCama' => fn ($query) => $query->where('estado', 'activa')
                    ->with('cama.cuarto.piso.eventoCabana'),
                'inscritoPor',
            ])
            ->where('evento_id', $root->id)
            ->where('tipo', 'club')
            ->orderByRaw('primera_evidencia_at IS NULL')
            ->orderBy('primera_evidencia_at')
            ->orderBy('id')
            ->get()
            ->all();
    }

    public function assertInscripcionAprobadaParaLote(User $actor, Event $event): EventoInscripcion
    {
        $ctx = $this->participation->assertClubDirectorContext($actor);
        $root = $this->resolveRoot($event);
        $inscripcion = $this->participation->findRootInscripcion($root, $ctx['organizacion_id']);
        if (! $inscripcion || ! $inscripcion->estaAprobada()) {
            throw ValidationException::withMessages([
                'inscripcion' => ['Debes tener la inscripción aprobada por el supervisor para elegir lote.'],
            ]);
        }

        return $inscripcion;
    }

    private function crearSeguroEvento(int $personaId, Event $root, int $inscripcionId): Seguro
    {
        $tipoId = $root->tipo_seguro_id
            ?? TipoSeguro::query()->where('tipo', TipoSeguro::TIPO_EVENTO)->where('activo', true)->value('id');
        if (! $tipoId) {
            throw ValidationException::withMessages([
                'seguro' => ['No hay tipo de seguro EVENTO configurado.'],
            ]);
        }

        $inicio = $root->seguro_fecha_inicio
            ? Carbon::parse($root->seguro_fecha_inicio)
            : Carbon::parse($root->starts_at)->startOfDay();
        $fin = $root->seguro_fecha_fin
            ? Carbon::parse($root->seguro_fecha_fin)
            : Carbon::parse($root->ends_at)->startOfDay();

        return Seguro::query()->updateOrCreate(
            [
                'persona_id' => $personaId,
                'evento_id' => $root->id,
                'tipo_seguro_id' => $tipoId,
                'inscripcion_id' => $inscripcionId,
            ],
            [
                'valor' => (float) ($root->seguro_valor ?? 0),
                'fecha_inicio' => $inicio->toDateString(),
                'fecha_fin' => $fin->toDateString(),
                'estado' => Seguro::ESTADO_PENDIENTE,
            ]
        );
    }

    private function storeComprobanteFile(
        UploadedFile $archivo,
        int $inscripcionId,
        int $actorId,
    ): StoredFile {
        $mime = $archivo->getMimeType() ?? '';
        if (! str_starts_with($mime, 'image/') && $mime !== 'application/pdf') {
            throw ValidationException::withMessages([
                'archivo' => ['Solo se permiten imágenes o PDF.'],
            ]);
        }

        $directory = "comprobantes/inscripcion-{$inscripcionId}";
        $path = $archivo->store($directory, 'public');

        return StoredFile::query()->create([
            'name' => $archivo->getClientOriginalName(),
            'path' => $path,
            'size' => $archivo->getSize() ?: 0,
            'mime_type' => $mime,
            'hash' => hash_file('sha256', $archivo->getRealPath()) ?: null,
            'uploaded_by' => $actorId,
        ]);
    }

    /**
     * @param  array<int, array<string, mixed>>  $reservas
     * @param  array<string, EventoInscripcionPersona>  $lineasPorReferencia
     */
    private function syncReservas(
        EventoInscripcion $inscripcion,
        Event $root,
        array $reservas,
        array $lineasPorReferencia,
    ): float {
        $total = 0.0;
        $visitantesConPasadia = [];
        foreach ($reservas as $row) {
            $ofertaId = (int) ($row['evento_producto_servicio_id'] ?? 0);
            $referencia = (string) ($row['participante_ref'] ?? '');
            $linea = $lineasPorReferencia[$referencia] ?? null;
            if (! $ofertaId || ! $linea) {
                throw ValidationException::withMessages([
                    'reservas' => ['La reserva referencia un participante inválido.'],
                ]);
            }
            $oferta = EventoProductoServicio::query()
                ->with('producto')
                ->where('evento_id', $root->id)
                ->where('id', $ofertaId)
                ->where('activo', true)
                ->first();
            if (! $oferta) {
                throw ValidationException::withMessages([
                    'reservas' => ["Servicio {$ofertaId} no disponible en este evento."],
                ]);
            }

            $tipo = $oferta->producto?->tipo;
            $esVisitantePasadia = $linea->tipo === EventoInscripcionPersona::TIPO_VISITANTE_PASADIA;
            if ($tipo === 'PASADIA' && ! $esVisitantePasadia) {
                throw ValidationException::withMessages([
                    'reservas' => ['El pasadía solo puede asignarse a visitantes externos al club.'],
                ]);
            }
            if ($esVisitantePasadia && $tipo !== 'PASADIA') {
                throw ValidationException::withMessages([
                    'reservas' => ['Los visitantes de pasadía solo pueden adquirir el servicio de pasadía.'],
                ]);
            }
            if ($esVisitantePasadia) {
                $visitantesConPasadia[$referencia] = true;
            }
            $precio = (float) $oferta->precio;
            $payload = [
                'evento_id' => $root->id,
                'evento_producto_servicio_id' => $oferta->id,
                'persona_id' => $linea->persona_id,
                'inscripcion_persona_id' => $linea->id,
                'inscripcion_id' => $inscripcion->id,
                'precio_unitario' => $precio,
                'estado' => EventoServicioReserva::ESTADO_RESERVADA,
            ];

            if ($tipo === 'CABANA') {
                $ini = ! empty($row['fecha_inicio'])
                    ? Carbon::parse((string) $row['fecha_inicio'])
                    : Carbon::parse($root->starts_at)->startOfDay();
                $dias = max(1, (int) ($row['cantidad'] ?? 1));
                $fin = ! empty($row['fecha_fin'])
                    ? Carbon::parse((string) $row['fecha_fin'])
                    : $ini->copy()->addDays($dias);
                if (! empty($row['fecha_fin'])) {
                    $dias = max(1, $ini->diffInDays($fin));
                }
                $payload['fecha_inicio'] = $ini->toDateString();
                $payload['fecha_fin'] = $fin->toDateString();
                $payload['cantidad_dias'] = $dias;
                $payload['precio_dia'] = $precio;
                $payload['cantidad'] = $dias;
                $payload['valor_total'] = $precio * $dias;
            } elseif ($tipo === 'PASADIA') {
                $fecha = Carbon::parse((string) ($row['fecha'] ?? $root->starts_at));
                $dias = max(1, (int) ($row['cantidad'] ?? 1));
                $payload['fecha'] = $fecha->toDateString();
                $payload['cantidad'] = $dias;
                $payload['cantidad_dias'] = $dias;
                $payload['precio_dia'] = $precio;
                $payload['valor_total'] = $precio * $dias;
            } else {
                $cant = max(1, (int) ($row['cantidad'] ?? 1));
                $payload['cantidad'] = $cant;
                $payload['valor_total'] = $precio * $cant;
            }

            $reserva = EventoServicioReserva::query()->create($payload);
            $total += (float) $reserva->valor_total;

            EventoPago::query()->create([
                'inscripcion_id' => $inscripcion->id,
                'pagable_type' => EventoServicioReserva::class,
                'pagable_id' => $reserva->id,
                'monto' => $reserva->valor_total,
                'moneda' => 'COP',
                'estado' => EventoPago::ESTADO_PENDIENTE,
            ]);
        }

        foreach ($lineasPorReferencia as $referencia => $linea) {
            if (
                $linea->tipo === EventoInscripcionPersona::TIPO_VISITANTE_PASADIA
                && ! isset($visitantesConPasadia[$referencia])
            ) {
                throw ValidationException::withMessages([
                    'reservas' => ['Todo visitante de pasadía debe tener asignado el servicio de pasadía.'],
                ]);
            }
        }

        return $total;
    }

    /** @param array<string, mixed> $data
     * @return list<array<string, mixed>>
     */
    private function normalizarParticipantes(array $data): array
    {
        if (! empty($data['participantes'])) {
            return array_values(array_map(function (array $row): array {
                $tipo = (string) ($row['tipo'] ?? EventoInscripcionPersona::TIPO_MIEMBRO);
                $personaId = isset($row['persona_id']) ? (int) $row['persona_id'] : null;
                if (in_array($tipo, [
                    EventoInscripcionPersona::TIPO_MIEMBRO,
                    EventoInscripcionPersona::TIPO_DIRECTIVA,
                ], true) && ! $personaId) {
                    throw ValidationException::withMessages([
                        'participantes' => ['Los miembros y la directiva deben corresponder a una persona del club.'],
                    ]);
                }
                $cargoDirectiva = $row['cargo_directiva'] ?? null;
                if (
                    $tipo === EventoInscripcionPersona::TIPO_DIRECTIVA
                    && ! in_array($cargoDirectiva, EventoInscripcionPersona::CARGOS_DIRECTIVA, true)
                ) {
                    throw ValidationException::withMessages([
                        'participantes' => ['Debes indicar el cargo de cada integrante de la directiva.'],
                    ]);
                }
                if ($tipo !== EventoInscripcionPersona::TIPO_DIRECTIVA) {
                    $cargoDirectiva = null;
                }

                return [
                    'ref' => (string) $row['ref'],
                    'persona_id' => $personaId,
                    'tipo' => $tipo,
                    'cargo_directiva' => $cargoDirectiva,
                    'nombre' => trim((string) ($row['nombre'] ?? '')),
                    'identificacion' => $row['identificacion'] ?? null,
                    'fecha_nacimiento' => $row['fecha_nacimiento'] ?? null,
                    'parentesco' => $row['parentesco'] ?? null,
                    'descuento_codigo' => $row['descuento_codigo'] ?? null,
                ];
            }, $data['participantes']));
        }

        return array_values(array_map(
            fn ($personaId) => [
                'ref' => 'persona:'.(int) $personaId,
                'persona_id' => (int) $personaId,
                'tipo' => EventoInscripcionPersona::TIPO_MIEMBRO,
                'cargo_directiva' => null,
                'nombre' => '',
                'identificacion' => null,
                'fecha_nacimiento' => null,
                'parentesco' => null,
                'descuento_codigo' => null,
            ],
            $data['persona_ids'] ?? []
        ));
    }

    private function resolverCodigoDescuentoCargo(Event $event, ?string $cargo): ?string
    {
        if (! $cargo) {
            return null;
        }

        $aliasPorCargo = [
            EventoInscripcionPersona::CARGO_DIRECTOR => ['director'],
            EventoInscripcionPersona::CARGO_SUBDIRECTOR => ['subdirector'],
            EventoInscripcionPersona::CARGO_SECRETARIO => ['secretario', 'secretaria'],
            EventoInscripcionPersona::CARGO_TESORERO => ['tesorero', 'economia', 'economa'],
        ];
        $codigosDisponibles = collect($event->descuentos_directiva ?? [])
            ->pluck('codigo')
            ->filter()
            ->all();

        return collect($aliasPorCargo[$cargo] ?? [])
            ->first(fn (string $codigo) => in_array($codigo, $codigosDisponibles, true));
    }

    /** @return array{0: string|null, 1: string|null, 2: float} */
    private function resolverDescuento(Event $event, ?string $codigo): array
    {
        if (! $codigo) {
            return [null, null, 0.0];
        }

        foreach ($event->descuentos_directiva ?? [] as $row) {
            if (is_array($row) && ($row['codigo'] ?? null) === $codigo) {
                return [
                    $codigo,
                    (string) ($row['nombre'] ?? $codigo),
                    max(0, min(100, (float) ($row['porcentaje'] ?? 0))),
                ];
            }
        }

        throw ValidationException::withMessages([
            'participantes' => ["El descuento {$codigo} no está configurado en el evento."],
        ]);
    }

    private function resolverPrecioBase(Event $event, string $tipo): float
    {
        if (! $event->requiere_pago) {
            return 0.0;
        }

        $fueraDeTiempo = $event->estaFueraDeFechaLimiteInscripcion();
        $precioGeneral = $fueraDeTiempo
            ? ($event->precio_fuera_tiempo ?? $event->precio)
            : $event->precio;

        return match ($tipo) {
            EventoInscripcionPersona::TIPO_VISITANTE_PASADIA => 0.0,
            EventoInscripcionPersona::TIPO_DIRECTIVA => (float) (
                ($fueraDeTiempo ? $event->precio_directiva_fuera_tiempo : null)
                    ?? $event->precio_directiva
                    ?? $precioGeneral
                    ?? 0
            ),
            EventoInscripcionPersona::TIPO_ACOMPANANTE => (float) (
                ($fueraDeTiempo ? $event->precio_acompanante_fuera_tiempo : null)
                    ?? $event->precio_acompanante
                    ?? $precioGeneral
                    ?? 0
            ),
            EventoInscripcionPersona::TIPO_ACOMPANANTE_MENOR => (float) (
                ($fueraDeTiempo ? $event->precio_acompanante_menor_fuera_tiempo : null)
                    ?? $event->precio_acompanante_menor
                    ?? ($fueraDeTiempo ? $event->precio_acompanante_fuera_tiempo : null)
                    ?? $event->precio_acompanante
                    ?? $precioGeneral
                    ?? 0
            ),
            default => (float) ($precioGeneral ?? 0),
        };
    }

    private function limpiarDetallePendiente(EventoInscripcion $inscripcion): void
    {
        $reservaIds = EventoServicioReserva::query()
            ->where('inscripcion_id', $inscripcion->id)
            ->pluck('id');
        if ($reservaIds->isNotEmpty()) {
            EventoPago::query()
                ->where('pagable_type', EventoServicioReserva::class)
                ->whereIn('pagable_id', $reservaIds)
                ->delete();
        }
        EventoServicioReserva::query()
            ->where('inscripcion_id', $inscripcion->id)
            ->get()
            ->each->delete();

        $lineaIds = EventoInscripcionPersona::query()
            ->where('inscripcion_id', $inscripcion->id)
            ->pluck('id');
        if ($lineaIds->isNotEmpty()) {
            EventoPago::query()
                ->where('pagable_type', EventoInscripcionPersona::class)
                ->whereIn('pagable_id', $lineaIds)
                ->delete();
        }
        EventoInscripcionPersona::query()->where('inscripcion_id', $inscripcion->id)->delete();

        $seguroIds = Seguro::query()
            ->where('inscripcion_id', $inscripcion->id)
            ->where('estado', Seguro::ESTADO_PENDIENTE)
            ->pluck('id');
        if ($seguroIds->isNotEmpty()) {
            EventoPago::query()
                ->where('pagable_type', Seguro::class)
                ->whereIn('pagable_id', $seguroIds)
                ->delete();
            Seguro::query()->whereIn('id', $seguroIds)->delete();
        }
    }

    /** @param  array<int>  $personaIds */
    private function assertPersonasBelongToClub(array $personaIds, int $organizacionId): void
    {
        $count = PersonaOrganizacion::query()
            ->where('organizacion_id', $organizacionId)
            ->whereIn('persona_id', $personaIds)
            ->where('estado', true)
            ->count();
        if ($count !== count($personaIds)) {
            throw ValidationException::withMessages([
                'persona_ids' => ['Algunas personas no pertenecen al club activo.'],
            ]);
        }
    }

    private function assertEventSupervisor(User $actor, Event $event): void
    {
        $root = $this->resolveRoot($event);
        if ($actor->isPlatformAdmin() || $actor->can('update', $root)) {
            return;
        }
        [$efectivos] = $root->resolveEffectiveSupervisores();
        $ids = $efectivos->pluck('id')->map(fn ($id) => (int) $id)->all();
        if (! in_array((int) $actor->id, $ids, true)) {
            throw new AccessDeniedHttpException('Solo supervisores del evento pueden realizar esta acción.');
        }
    }

    private function resolveRoot(Event $event): Event
    {
        $root = $event;
        while ($root->evento_padre_id) {
            $root = Event::query()->findOrFail($root->evento_padre_id);
        }

        return $root;
    }
}
