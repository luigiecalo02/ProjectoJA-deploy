<?php

namespace App\Modules\Events\Services;

use App\Models\User;
use App\Modules\Auth\Services\AccountMailService;
use App\Modules\Cabanas\Models\EventoCabana;
use App\Modules\Clubs\Models\Persona;
use App\Modules\Events\Models\Event;
use App\Modules\Events\Models\EventoInscripcion;
use App\Modules\Events\Models\EventoInscripcionComprobante;
use App\Modules\Events\Models\EventoInscripcionPersona;
use App\Modules\Events\Models\EventoPago;
use App\Modules\Events\Models\EventoProductoServicio;
use App\Modules\Events\Models\EventoServicioReserva;
use App\Modules\Events\Models\Seguro;
use App\Modules\Events\Models\TipoSeguro;
use App\Modules\Settings\Services\CuentaBancariaService;
use App\Modules\Shared\Models\StoredFile;
use App\Modules\Shared\Services\ImageOptimizer;
use App\Modules\Terrains\Models\EventoLote;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class PublicEventoService
{
    public function __construct(
        private readonly ImageOptimizer $imageOptimizer,
        private readonly CuentaBancariaService $cuentasBancarias,
        private readonly SeguroService $seguroService,
        private readonly AccountMailService $accountMail,
    ) {}

    /** @return Collection<int, Event> */
    public function list(): Collection
    {
        return $this->eligibleQuery()
            ->with('catalogLugar')
            ->orderBy('starts_at')
            ->orderBy('name')
            ->get();
    }

    public function findEligibleOrFail(int $id): Event
    {
        $event = $this->eligibleQuery()
            ->with(['catalogLugar', 'cuentaBancaria.qrFile'])
            ->whereKey($id)
            ->first();

        if (! $event) {
            abort(404, 'Este evento no está disponible para inscripción pública.');
        }

        return $event;
    }

    /** @return array<string, mixed> */
    public function cardPayload(Event $event): array
    {
        return [
            'id' => $event->id,
            'name' => $event->name,
            'descripcion' => $event->descripcion,
            'lugar' => $event->lugar,
            'lugar_catalogo' => $event->relationLoaded('catalogLugar') && $event->catalogLugar
                ? [
                    'id' => $event->catalogLugar->id,
                    'nombre' => $event->catalogLugar->nombre,
                    'latitud' => $event->catalogLugar->latitud,
                    'longitud' => $event->catalogLugar->longitud,
                ]
                : null,
            'image_url' => $event->image_url,
            'banner_url' => $event->banner_url,
            'starts_at' => $event->starts_at?->toIso8601String(),
            'ends_at' => $event->ends_at?->toIso8601String(),
            'requiere_pago' => (bool) $event->requiere_pago,
            'precio' => $this->precioInscripcion($event),
            'precio_lista' => $event->precio !== null ? (float) $event->precio : null,
            'fuera_de_tiempo' => $event->estaFueraDeFechaLimiteInscripcion(),
            'usar_lotes' => (bool) $event->usar_lotes,
            'usar_cabanas' => (bool) $event->usar_cabanas,
            'fecha_limite_inscripcion' => $event->fecha_limite_inscripcion?->toIso8601String(),
        ];
    }

    /** @return array<string, mixed> */
    public function detail(Event $event): array
    {
        $dias = $this->nochesEvento($event);
        $ofertaCabana = $this->ofertaCabana($event);

        return [
            ...$this->cardPayload($event),
            'reglas' => $event->reglas,
            'requiere_seguro' => (bool) $event->requiere_seguro,
            'seguro_valor' => $event->seguro_valor !== null ? (float) $event->seguro_valor : null,
            'metodo_pago' => $event->metodo_pago,
            'cuenta_bancaria' => $this->cuentasBancarias->toPayload($event->cuentaBancaria),
            'noches' => $dias,
            'oferta_cabana' => $ofertaCabana
                ? [
                    'id' => $ofertaCabana->id,
                    'nombre' => $ofertaCabana->producto?->nombre ?? 'Cabaña',
                    'precio_dia' => (float) $ofertaCabana->precio,
                    'dias' => $dias,
                    'total' => round((float) $ofertaCabana->precio * $dias, 2),
                ]
                : null,
            'lotes' => $event->usar_lotes ? $this->lotesDisponibles($event) : [],
            'cabanas' => $event->usar_cabanas ? $this->cabanasDisponibles($event) : [],
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public function enroll(Event $event, array $data, ?UploadedFile $comprobante): array
    {
        $event = $this->findEligibleOrFail((int) $event->id);
        $this->assertCupoDisponible($event);

        $crearUsuario = $this->wantsAccount($data);
        $correo = strtolower(trim((string) ($data['correo'] ?? '')));

        return DB::transaction(function () use ($event, $data, $comprobante, $crearUsuario, $correo) {
            $persona = $this->resolvePersona($data, $correo);
            $this->assertSinInscripcionActiva($event, $persona);

            $user = $crearUsuario
                ? $this->createOptionalUser($persona, $correo, (string) $data['password'])
                : null;

            $loteId = $this->reservarLote($event, isset($data['evento_lote_id']) ? (int) $data['evento_lote_id'] : null);
            $cabanaId = $this->reservarCabana($event, isset($data['evento_cabana_id']) ? (int) $data['evento_cabana_id'] : null);

            $inscripcion = EventoInscripcion::query()->create([
                'evento_id' => $event->id,
                'tipo' => EventoInscripcion::TIPO_INDIVIDUAL,
                'persona_id' => $persona->id,
                'organizacion_id' => null,
                'evento_lote_id' => $loteId,
                'evento_cabana_id' => $cabanaId,
                'estado' => EventoInscripcion::ESTADO_PENDIENTE_REVISION,
                'inscrito_por' => $user?->id,
                'total_declarado' => 0,
            ]);

            $valorInscripcion = $this->precioInscripcion($event);
            $valorSeguro = 0.0;

            $linea = EventoInscripcionPersona::query()->create([
                'inscripcion_id' => $inscripcion->id,
                'persona_id' => $persona->id,
                'tipo' => EventoInscripcionPersona::TIPO_MIEMBRO,
                'referencia_cliente' => 'titular',
                'nombre_snapshot' => $persona->full_name,
                'identificacion_snapshot' => $persona->identificacion,
                'fecha_nacimiento_snapshot' => $persona->fecha_nacimiento?->toDateString(),
                'valor_base' => $valorInscripcion,
                'valor_descuento' => 0,
                'valor_inscripcion' => $valorInscripcion,
                'valor_seguro' => 0,
                'estado' => EventoInscripcionPersona::ESTADO_CONFIRMADA,
            ]);

            EventoPago::query()->create([
                'pagable_type' => EventoInscripcionPersona::class,
                'pagable_id' => $linea->id,
                'inscripcion_id' => $inscripcion->id,
                'monto' => $valorInscripcion,
                'moneda' => 'COP',
                'estado' => EventoPago::ESTADO_PENDIENTE,
            ]);

            if ($event->requiere_seguro) {
                $cobertura = $this->seguroService->estaCubierta($persona->id, $event);
                if (! $cobertura['cubierta']) {
                    $seguro = $this->crearSeguroEvento($persona->id, $event, $inscripcion->id);
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
                    $linea->update(['valor_seguro' => $valorSeguro]);
                }
            }

            $valorCabana = $this->crearReservaCabana($event, $inscripcion, $linea, $cabanaId);
            $total = round($valorInscripcion + $valorSeguro + $valorCabana, 2);

            $inscripcion->update(['total_declarado' => $total]);

            if ($total > 0) {
                if (! $comprobante) {
                    throw ValidationException::withMessages([
                        'comprobante' => ['Debes subir el comprobante de pago para completar la inscripción.'],
                    ]);
                }
                $this->attachComprobante($inscripcion, $comprobante, (float) ($data['comprobante_valor'] ?? $total), $user?->id);
            }

            $this->accountMail->trySendInscripcionPublica(
                $correo,
                $persona->full_name,
                $event,
                $inscripcion->fresh(),
                (bool) $user,
            );
            if ($user) {
                $this->accountMail->trySendVerification($user);
            }

            return [
                'inscripcion_id' => $inscripcion->id,
                'total' => $total,
                'usuario_creado' => (bool) $user,
                'correo' => $correo,
                'correo_enmascarado' => $this->accountMail->maskEmail($correo),
            ];
        });
    }

    public function eligibleQuery(): Builder
    {
        return Event::query()
            ->whereNull('evento_padre_id')
            ->where('is_active', true)
            ->whereIn('estado', [Event::ESTADO_PUBLICADO, Event::ESTADO_EN_PROCESO])
            ->where('visibilidad', Event::VISIBILIDAD_PUBLICO)
            ->where('permite_inscripcion_individual', true)
            ->whereDoesntHave('tiposOrganizacion')
            ->where(function (Builder $query) {
                $query->whereNull('starts_at')
                    ->orWhere('starts_at', '>=', now()->startOfDay());
            });
    }

    private function precioInscripcion(Event $event): float
    {
        if (! $event->requiere_pago) {
            return 0.0;
        }

        $fuera = $event->estaFueraDeFechaLimiteInscripcion();

        return (float) ($fuera
            ? ($event->precio_fuera_tiempo ?? $event->precio ?? 0)
            : ($event->precio ?? 0));
    }

    private function nochesEvento(Event $event): int
    {
        if (! $event->starts_at) {
            return 1;
        }
        $inicio = Carbon::parse($event->starts_at)->startOfDay();
        $fin = $event->ends_at
            ? Carbon::parse($event->ends_at)->startOfDay()
            : $inicio->copy()->addDay();

        return max(1, $inicio->diffInDays($fin));
    }

    private function ofertaCabana(Event $event): ?EventoProductoServicio
    {
        return EventoProductoServicio::query()
            ->with('producto')
            ->where('evento_id', $event->id)
            ->where('activo', true)
            ->whereHas('producto', fn (Builder $query) => $query->where('tipo', 'CABANA'))
            ->orderBy('id')
            ->first();
    }

    /** @return list<array<string, mixed>> */
    private function lotesDisponibles(Event $event): array
    {
        return EventoLote::query()
            ->whereHas('eventoTerreno', fn (Builder $query) => $query->where('evento_id', $event->id))
            ->where('estado', EventoLote::ESTADO_DISPONIBLE)
            ->orderBy('orden')
            ->orderBy('codigo')
            ->get()
            ->map(fn (EventoLote $lote) => [
                'id' => $lote->id,
                'codigo' => $lote->codigo,
                'nombre' => $lote->nombre,
                'capacidad_maxima' => $lote->capacidad_maxima,
                'estado' => $lote->estado,
            ])
            ->values()
            ->all();
    }

    /** @return list<array<string, mixed>> */
    private function cabanasDisponibles(Event $event): array
    {
        $reservadas = EventoInscripcion::query()
            ->where('evento_id', $event->id)
            ->where('tipo', EventoInscripcion::TIPO_INDIVIDUAL)
            ->whereNotNull('evento_cabana_id')
            ->where('estado', '!=', EventoInscripcion::ESTADO_NO_APROBADA)
            ->pluck('evento_cabana_id')
            ->map(fn ($id) => (int) $id)
            ->all();

        return EventoCabana::query()
            ->where('evento_id', $event->id)
            ->with(['pisos.cuartos.camas'])
            ->orderBy('orden')
            ->orderBy('nombre')
            ->get()
            ->map(function (EventoCabana $cabana) use ($reservadas) {
                $capacidad = (int) $cabana->pisos->sum(
                    fn ($piso) => $piso->cuartos->sum(
                        fn ($cuarto) => $cuarto->camas->sum(fn ($cama) => (int) ($cama->capacidad ?? 1))
                    )
                );

                return [
                    'id' => $cabana->id,
                    'nombre' => $cabana->nombre,
                    'descripcion' => $cabana->descripcion,
                    'image_url' => $cabana->image_url,
                    'capacidad' => $capacidad,
                    'disponible' => ! in_array((int) $cabana->id, $reservadas, true),
                ];
            })
            ->values()
            ->all();
    }

    private function assertCupoDisponible(Event $event): void
    {
        if ($event->cupo_ilimitado || ! $event->cupo_maximo) {
            return;
        }

        $ocupados = EventoInscripcionPersona::query()
            ->whereHas('inscripcion', function (Builder $query) use ($event) {
                $query->where('evento_id', $event->id)
                    ->where('estado', '!=', EventoInscripcion::ESTADO_NO_APROBADA);
            })
            ->where('estado', '!=', EventoInscripcionPersona::ESTADO_CANCELADA)
            ->count();

        if ($ocupados >= (int) $event->cupo_maximo) {
            throw ValidationException::withMessages([
                'evento' => ['El evento ya no tiene cupos disponibles.'],
            ]);
        }
    }

    /** @param  array<string, mixed>  $data */
    private function wantsAccount(array $data): bool
    {
        $raw = $data['crear_usuario'] ?? false;

        return filter_var($raw, FILTER_VALIDATE_BOOLEAN);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function resolvePersona(array $data, string $correo): Persona
    {
        $identificacion = trim((string) $data['identificacion']);
        $persona = Persona::query()->where('identificacion', $identificacion)->first();

        $payload = [
            'tipo_identificacion' => $data['tipo_identificacion'],
            'identificacion' => $identificacion,
            'nombre1' => trim((string) $data['nombre1']),
            'nombre2' => filled($data['nombre2'] ?? null) ? trim((string) $data['nombre2']) : null,
            'apellido1' => trim((string) $data['apellido1']),
            'apellido2' => filled($data['apellido2'] ?? null) ? trim((string) $data['apellido2']) : null,
            'fecha_nacimiento' => $data['fecha_nacimiento'] ?? null,
            'sexo' => $data['sexo'] ?? null,
            'telefono' => filled($data['telefono'] ?? null) ? trim((string) $data['telefono']) : null,
            'correo' => $correo,
        ];

        if (! $persona) {
            return Persona::query()->create($payload);
        }

        $persona->fill([
            'telefono' => $payload['telefono'] ?: $persona->telefono,
            'correo' => $correo,
            'sexo' => $payload['sexo'] ?: $persona->sexo,
            'fecha_nacimiento' => $payload['fecha_nacimiento'] ?: $persona->fecha_nacimiento,
        ])->save();

        return $persona->fresh();
    }

    private function assertSinInscripcionActiva(Event $event, Persona $persona): void
    {
        $existe = EventoInscripcion::query()
            ->where('evento_id', $event->id)
            ->where('tipo', EventoInscripcion::TIPO_INDIVIDUAL)
            ->where('persona_id', $persona->id)
            ->where('estado', '!=', EventoInscripcion::ESTADO_NO_APROBADA)
            ->exists();

        if ($existe) {
            throw ValidationException::withMessages([
                'identificacion' => ['Ya tienes una inscripción vigente en este evento.'],
            ]);
        }
    }

    private function createOptionalUser(Persona $persona, string $correo, string $password): User
    {
        if ($persona->user) {
            throw ValidationException::withMessages([
                'crear_usuario' => ['Esta persona ya tiene un usuario. Inicia sesión o continúa sin crear cuenta.'],
            ]);
        }
        if (User::query()->whereRaw('LOWER(email) = ?', [$correo])->exists()) {
            throw ValidationException::withMessages([
                'correo' => ['Ya existe una cuenta con este correo.'],
            ]);
        }

        $user = User::query()->create([
            'persona_id' => $persona->id,
            'name' => $persona->full_name,
            'email' => $correo,
            'password' => $password,
            'is_active' => false,
            'email_verified_at' => null,
        ]);

        return $user;
    }

    private function reservarLote(Event $event, ?int $loteId): ?int
    {
        if (! $event->usar_lotes || ! $loteId) {
            return null;
        }

        $lote = EventoLote::query()
            ->whereKey($loteId)
            ->whereHas('eventoTerreno', fn (Builder $query) => $query->where('evento_id', $event->id))
            ->lockForUpdate()
            ->first();

        if (! $lote || $lote->estado !== EventoLote::ESTADO_DISPONIBLE) {
            throw ValidationException::withMessages([
                'evento_lote_id' => ['El lote seleccionado no está disponible.'],
            ]);
        }

        $lote->update(['estado' => EventoLote::ESTADO_RESERVADO]);

        return (int) $lote->id;
    }

    private function reservarCabana(Event $event, ?int $cabanaId): ?int
    {
        if (! $event->usar_cabanas || ! $cabanaId) {
            return null;
        }

        $cabana = EventoCabana::query()
            ->whereKey($cabanaId)
            ->where('evento_id', $event->id)
            ->lockForUpdate()
            ->first();

        if (! $cabana) {
            throw ValidationException::withMessages([
                'evento_cabana_id' => ['La cabaña seleccionada no pertenece a este evento.'],
            ]);
        }

        $ocupada = EventoInscripcion::query()
            ->where('evento_id', $event->id)
            ->where('evento_cabana_id', $cabana->id)
            ->where('estado', '!=', EventoInscripcion::ESTADO_NO_APROBADA)
            ->exists();

        if ($ocupada) {
            throw ValidationException::withMessages([
                'evento_cabana_id' => ['La cabaña seleccionada ya fue reservada.'],
            ]);
        }

        return (int) $cabana->id;
    }

    private function crearReservaCabana(
        Event $event,
        EventoInscripcion $inscripcion,
        EventoInscripcionPersona $linea,
        ?int $cabanaId,
    ): float {
        if (! $cabanaId) {
            return 0.0;
        }

        $oferta = $this->ofertaCabana($event);
        $dias = $this->nochesEvento($event);
        $precioDia = $oferta ? (float) $oferta->precio : 0.0;
        $total = round($precioDia * $dias, 2);

        if (! $oferta || $total <= 0) {
            return 0.0;
        }

        $inicio = Carbon::parse($event->starts_at)->startOfDay();
        $fin = $inicio->copy()->addDays($dias);

        $reserva = EventoServicioReserva::query()->create([
            'evento_id' => $event->id,
            'evento_producto_servicio_id' => $oferta->id,
            'persona_id' => $linea->persona_id,
            'inscripcion_persona_id' => $linea->id,
            'inscripcion_id' => $inscripcion->id,
            'precio_unitario' => $precioDia,
            'cantidad' => $dias,
            'valor_total' => $total,
            'fecha_inicio' => $inicio->toDateString(),
            'fecha_fin' => $fin->toDateString(),
            'cantidad_dias' => $dias,
            'precio_dia' => $precioDia,
            'estado' => EventoServicioReserva::ESTADO_RESERVADA,
        ]);

        EventoPago::query()->create([
            'inscripcion_id' => $inscripcion->id,
            'pagable_type' => EventoServicioReserva::class,
            'pagable_id' => $reserva->id,
            'monto' => $total,
            'moneda' => 'COP',
            'estado' => EventoPago::ESTADO_PENDIENTE,
        ]);

        return $total;
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

    private function attachComprobante(
        EventoInscripcion $inscripcion,
        UploadedFile $archivo,
        float $valor,
        ?int $actorId,
    ): EventoInscripcionComprobante {
        $mime = $archivo->getMimeType() ?? '';
        if (! str_starts_with($mime, 'image/') && $mime !== 'application/pdf') {
            throw ValidationException::withMessages([
                'comprobante' => ['Solo se permiten imágenes o PDF.'],
            ]);
        }

        $stored = $this->imageOptimizer->store($archivo, "comprobantes/inscripcion-{$inscripcion->id}", 'comp');
        $file = StoredFile::query()->create([
            'name' => $archivo->getClientOriginalName(),
            'path' => $stored->path,
            'size' => $stored->size,
            'mime_type' => $stored->mime,
            'hash' => $stored->hash,
            'uploaded_by' => $actorId,
        ]);

        return EventoInscripcionComprobante::query()->create([
            'inscripcion_id' => $inscripcion->id,
            'file_id' => $file->id,
            'valor' => $valor,
            'estado' => EventoInscripcionComprobante::ESTADO_PENDIENTE,
            'subido_por' => $actorId,
        ]);
    }
}
