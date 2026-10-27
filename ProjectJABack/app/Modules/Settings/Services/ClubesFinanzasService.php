<?php

namespace App\Modules\Settings\Services;

use App\Models\User;
use App\Modules\Events\Models\Event;
use App\Modules\Events\Models\EventoParticipacionAbono;
use App\Modules\Events\Models\TipoEvento;
use App\Modules\Settings\Models\AppSetting;
use App\Modules\Settings\Models\ClubesCajaCuenta;
use App\Modules\Settings\Models\ClubesCajaMovimiento;
use App\Modules\Settings\Models\CuentaBancaria;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response;

final class ClubesFinanzasService
{
    private ?bool $orgHasAssignedCuentas = null;

    /**
     * @return array<string, mixed>
     */
    public function resumen(User $actor, ?string $desde, ?string $hasta, ?int $cuentaBancariaId): array
    {
        $orgId = $this->assertCanView($actor);
        $this->ensureCuentas($orgId);
        $this->syncAbonosPendientes($orgId);
        $this->syncCuentaBancariaEnAbonos($orgId);

        $from = $desde ? Carbon::parse($desde)->startOfDay() : now()->startOfYear();
        $to = $hasta ? Carbon::parse($hasta)->endOfDay() : now()->endOfDay();

        $movimientos = ClubesCajaMovimiento::query()
            ->with(['cuenta', 'cuentaBancaria', 'evento'])
            ->where('organizacion_id', $orgId)
            ->whereBetween('occurred_at', [$from, $to])
            ->tap(fn ($query) => $this->applyCuentaBancariaScope($query, $orgId, $cuentaBancariaId))
            ->tap(fn ($query) => $this->excludeCancelledEvents($query))
            ->orderByDesc('occurred_at')
            ->orderByDesc('id')
            ->get();

        $ingresos = round((float) $movimientos->where('tipo', ClubesCajaMovimiento::TIPO_INGRESO)->sum('monto'), 2);
        $gastos = round((float) $movimientos->where('tipo', ClubesCajaMovimiento::TIPO_EGRESO)->sum('monto'), 2);
        $disponible = round($ingresos - $gastos, 2);

        $distribucion = $this->distribucionActividades($orgId, $from, $to, $cuentaBancariaId);
        $serie = $this->serieMensual($movimientos, $from, $to);
        $cuentas = $this->saldosCuentas($orgId, $from, $to, $cuentaBancariaId);
        $actividades = $distribucion['actividades'];
        $partes = $distribucion['partes'];
        $porDistribuir = round(
            ($partes['participantes']['amount'] ?? 0)
            + ($partes['club']['amount'] ?? 0)
            + ($partes['extras']['amount'] ?? 0),
            2,
        );

        return [
            'desde' => $from->toDateString(),
            'hasta' => $to->toDateString(),
            'cuenta_bancaria_id' => $cuentaBancariaId,
            'kpis' => [
                'ingresos' => $ingresos,
                'gastos' => $gastos,
                'disponible' => $disponible,
                'por_distribuir' => $porDistribuir,
                'actividades' => count($actividades),
                'conceptos_gasto' => $movimientos->where('tipo', ClubesCajaMovimiento::TIPO_EGRESO)->count(),
            ],
            'distribucion' => array_values($partes),
            'serie_mensual' => $serie,
            'cuentas' => $cuentas,
            'movimientos' => $movimientos->take(25)->map(fn (ClubesCajaMovimiento $row) => $this->presentMovimiento($row))->values()->all(),
            'actividades' => $actividades,
            'eventos' => $this->opcionesEventos($orgId),
            'cuentas_bancarias' => $this->opcionesCuentasBancariasUsadas($orgId),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function storeMovimiento(User $actor, array $data): array
    {
        $orgId = $this->assertCanUpdate($actor);
        $this->ensureCuentas($orgId);

        $cuentaBancaria = $this->cuentaBancariaDeOrg($orgId, (int) ($data['cuenta_bancaria_id'] ?? 0));
        abort_unless(
            $cuentaBancaria instanceof CuentaBancaria,
            Response::HTTP_UNPROCESSABLE_ENTITY,
            'Elige la cuenta bancaria de donde sale o entra el dinero.',
        );

        $cuenta = $this->cuentaPorTipo($orgId, ClubesCajaCuenta::TIPO_BANCO)
            ?? $this->cuentaPorTipo($orgId, ClubesCajaCuenta::TIPO_CAJA);
        abort_unless($cuenta, Response::HTTP_UNPROCESSABLE_ENTITY, 'No hay una cuenta de caja para registrar el movimiento.');

        $eventoId = isset($data['evento_id']) ? (int) $data['evento_id'] : null;
        if ($eventoId) {
            $event = Event::query()->where('organizacion_id', $orgId)->where('id', $eventoId)->first();
            abort_unless(
                $event instanceof Event && ! $this->eventIsCancelled($event),
                Response::HTTP_UNPROCESSABLE_ENTITY,
                'No se registra caja en una actividad cancelada.',
            );
        }

        $movimiento = ClubesCajaMovimiento::query()->create([
            'organizacion_id' => $orgId,
            'cuenta_id' => $cuenta->id,
            'cuenta_bancaria_id' => $cuentaBancaria->id,
            'tipo' => $data['tipo'],
            'origen' => $data['origen'] ?? ClubesCajaMovimiento::ORIGEN_MANUAL,
            'monto' => round((float) $data['monto'], 2),
            'concepto' => trim((string) $data['concepto']),
            'evento_id' => $eventoId,
            'registrado_por' => $actor->id,
            'occurred_at' => isset($data['occurred_at'])
                ? Carbon::parse($data['occurred_at'])
                : now(),
        ]);

        return $this->presentMovimiento($movimiento->load(['cuenta', 'cuentaBancaria', 'evento']));
    }

    public function deleteMovimiento(User $actor, int $id): void
    {
        abort_unless(
            $actor->isSuperAdmin(),
            Response::HTTP_FORBIDDEN,
            'Solo el superadministrador puede eliminar movimientos de caja.',
        );
        $orgId = $this->assertCanView($actor);
        $row = ClubesCajaMovimiento::query()
            ->where('organizacion_id', $orgId)
            ->where('id', $id)
            ->firstOrFail();

        if (
            $row->origen === ClubesCajaMovimiento::ORIGEN_ABONO
            && $row->referencia_tipo === EventoParticipacionAbono::class
            && $row->referencia_id
        ) {
            $abono = EventoParticipacionAbono::query()->find((int) $row->referencia_id);
            if ($abono) {
                $abono->estado = EventoParticipacionAbono::ESTADO_ELIMINADO;
                $abono->save();
            }
            ClubesCajaMovimiento::query()
                ->where('organizacion_id', $orgId)
                ->where('referencia_tipo', EventoParticipacionAbono::class)
                ->where('referencia_id', $row->referencia_id)
                ->delete();

            return;
        }

        $row->delete();
    }

    public function registrarAbonoAprobado(EventoParticipacionAbono $abono): void
    {
        if (! $this->tablesReady()) {
            return;
        }

        $abono->loadMissing('evento');
        if ($this->eventIsCancelled($abono->evento)) {
            return;
        }

        $orgId = (int) $abono->organizacion_id;
        $this->ensureCuentas($orgId);
        $cuentaBancariaId = $this->cuentaBancariaIdDelEvento($abono->evento, $orgId);
        $cuenta = $this->cajaCuentaParaMovimiento($orgId, $cuentaBancariaId);
        if (! $cuenta) {
            return;
        }

        $existing = ClubesCajaMovimiento::query()
            ->where('referencia_tipo', EventoParticipacionAbono::class)
            ->where('referencia_id', $abono->id)
            ->where('tipo', ClubesCajaMovimiento::TIPO_INGRESO)
            ->where('origen', ClubesCajaMovimiento::ORIGEN_ABONO)
            ->first();

        if ($existing) {
            $existing->update([
                'monto' => round((float) $abono->monto, 2),
                'concepto' => $this->conceptoAbono($abono),
                'evento_id' => (int) $abono->evento_id,
                'cuenta_bancaria_id' => $cuentaBancariaId,
                'occurred_at' => $abono->updated_at ?? now(),
            ]);

            return;
        }

        ClubesCajaMovimiento::query()->create([
            'organizacion_id' => $orgId,
            'cuenta_id' => $cuenta->id,
            'cuenta_bancaria_id' => $cuentaBancariaId,
            'tipo' => ClubesCajaMovimiento::TIPO_INGRESO,
            'origen' => ClubesCajaMovimiento::ORIGEN_ABONO,
            'monto' => round((float) $abono->monto, 2),
            'concepto' => $this->conceptoAbono($abono),
            'evento_id' => (int) $abono->evento_id,
            'referencia_tipo' => EventoParticipacionAbono::class,
            'referencia_id' => $abono->id,
            'registrado_por' => $abono->registrado_por,
            'occurred_at' => $abono->updated_at ?? $abono->created_at ?? now(),
        ]);
    }

    public function anularAbono(EventoParticipacionAbono $abono): void
    {
        if (! $this->tablesReady()) {
            return;
        }

        $abono->loadMissing('evento');
        $orgId = (int) $abono->organizacion_id;
        $this->ensureCuentas($orgId);
        $cuentaBancariaId = $this->cuentaBancariaIdDelEvento($abono->evento, $orgId);
        $cuenta = $this->cajaCuentaParaMovimiento($orgId, $cuentaBancariaId);
        if (! $cuenta) {
            return;
        }

        $yaAnulado = ClubesCajaMovimiento::query()
            ->where('referencia_tipo', EventoParticipacionAbono::class)
            ->where('referencia_id', $abono->id)
            ->where('tipo', ClubesCajaMovimiento::TIPO_EGRESO)
            ->where('origen', ClubesCajaMovimiento::ORIGEN_AJUSTE)
            ->exists();
        if ($yaAnulado) {
            return;
        }

        $ingreso = ClubesCajaMovimiento::query()
            ->where('referencia_tipo', EventoParticipacionAbono::class)
            ->where('referencia_id', $abono->id)
            ->where('tipo', ClubesCajaMovimiento::TIPO_INGRESO)
            ->first();

        if (! $ingreso) {
            return;
        }

        ClubesCajaMovimiento::query()->create([
            'organizacion_id' => $orgId,
            'cuenta_id' => $cuenta->id,
            'cuenta_bancaria_id' => $ingreso->cuenta_bancaria_id
                ? (int) $ingreso->cuenta_bancaria_id
                : $cuentaBancariaId,
            'tipo' => ClubesCajaMovimiento::TIPO_EGRESO,
            'origen' => ClubesCajaMovimiento::ORIGEN_AJUSTE,
            'monto' => round((float) $ingreso->monto, 2),
            'concepto' => 'Reverso · '.$ingreso->concepto,
            'evento_id' => (int) $abono->evento_id,
            'referencia_tipo' => EventoParticipacionAbono::class,
            'referencia_id' => $abono->id,
            'registrado_por' => $abono->registrado_por,
            'occurred_at' => now(),
        ]);
    }

    private function ensureCuentas(int $orgId): void
    {
        if (! $this->tablesReady()) {
            return;
        }

        $defaults = [
            ['nombre' => 'Caja general', 'tipo' => ClubesCajaCuenta::TIPO_CAJA, 'orden' => 1],
            ['nombre' => 'Cuenta bancaria', 'tipo' => ClubesCajaCuenta::TIPO_BANCO, 'orden' => 2],
            ['nombre' => 'Efectivo en eventos', 'tipo' => ClubesCajaCuenta::TIPO_EFECTIVO_EVENTOS, 'orden' => 3],
        ];

        foreach ($defaults as $row) {
            ClubesCajaCuenta::query()->firstOrCreate(
                [
                    'organizacion_id' => $orgId,
                    'nombre' => $row['nombre'],
                ],
                [
                    'tipo' => $row['tipo'],
                    'orden' => $row['orden'],
                    'activa' => true,
                ],
            );
        }
    }

    private function syncAbonosPendientes(int $orgId): void
    {
        if (! $this->tablesReady()) {
            return;
        }

        $synced = ClubesCajaMovimiento::query()
            ->where('organizacion_id', $orgId)
            ->where('origen', ClubesCajaMovimiento::ORIGEN_ABONO)
            ->where('referencia_tipo', EventoParticipacionAbono::class)
            ->pluck('referencia_id')
            ->all();

        EventoParticipacionAbono::query()
            ->with('evento')
            ->where('organizacion_id', $orgId)
            ->where(function ($query) {
                $query->where('estado', EventoParticipacionAbono::ESTADO_APROBADO)
                    ->orWhereNull('estado');
            })
            ->whereHas('evento', fn ($evento) => $this->whereEventNotCancelled($evento))
            ->when($synced !== [], fn ($query) => $query->whereNotIn('id', $synced))
            ->orderBy('id')
            ->each(fn (EventoParticipacionAbono $abono) => $this->registrarAbonoAprobado($abono));
    }

    private function syncCuentaBancariaEnAbonos(int $orgId): void
    {
        if (! $this->tablesReady()) {
            return;
        }

        ClubesCajaMovimiento::query()
            ->with(['evento.padre'])
            ->where('organizacion_id', $orgId)
            ->where(function ($query) {
                $query->where('origen', ClubesCajaMovimiento::ORIGEN_ABONO)
                    ->orWhere(function ($inner) {
                        $inner->where('origen', ClubesCajaMovimiento::ORIGEN_AJUSTE)
                            ->where('referencia_tipo', EventoParticipacionAbono::class);
                    });
            })
            ->whereNull('cuenta_bancaria_id')
            ->orderBy('id')
            ->each(function (ClubesCajaMovimiento $row) use ($orgId) {
                $cuentaBancariaId = $this->cuentaBancariaIdDelEvento($row->evento, $orgId);
                if (! $cuentaBancariaId) {
                    return;
                }
                $caja = $this->cajaCuentaParaMovimiento($orgId, $cuentaBancariaId);
                $row->update(array_filter([
                    'cuenta_bancaria_id' => $cuentaBancariaId,
                    'cuenta_id' => $caja?->id,
                ]));
            });
    }

    /**
     * @return array{partes: array<string, array<string, mixed>>, actividades: list<array<string, mixed>>}
     */
    private function distribucionActividades(int $orgId, Carbon $from, Carbon $to, ?int $cuentaBancariaId): array
    {
        $events = Event::query()
            ->where('organizacion_id', $orgId)
            ->whereNull('evento_padre_id')
            ->tap(fn ($query) => $this->whereEventNotCancelled($query))
            ->whereHas('tipoEvento', fn ($tipo) => $tipo->where('slug', TipoEvento::SLUG_ACTIVIDAD_ECONOMICA))
            ->when($cuentaBancariaId, fn ($query) => $this->whereEventoDeCuentaBancaria($query, $orgId, $cuentaBancariaId))
            ->orderBy('starts_at')
            ->get();

        $abonos = EventoParticipacionAbono::query()
            ->where('organizacion_id', $orgId)
            ->whereIn('evento_id', $events->pluck('id'))
            ->whereBetween('created_at', [$from, $to])
            ->get()
            ->groupBy(fn (EventoParticipacionAbono $abono) => (int) $abono->evento_id);

        $totales = [
            'participantes' => 0.0,
            'diezmo' => 0.0,
            'ofrenda' => 0.0,
            'club' => 0.0,
            'extras' => 0.0,
            'capital' => 0.0,
        ];
        $actividades = [];

        foreach ($events as $event) {
            $lista = $abonos->get((int) $event->id, collect());
            $abonado = round((float) $lista->sum(fn (EventoParticipacionAbono $abono) => $abono->montoContable()), 2);
            if ($abonado <= 0 && ! $cuentaBancariaId) {
                continue;
            }

            $proyeccion = max(0.0, (float) ($event->ganancia_proyeccion ?? 0));
            $factor = $proyeccion > 0 ? min(1, $abonado / $proyeccion) : 0.0;
            $parts = [
                'participantes' => round((float) ($event->ganancia_participantes ?? 0) * $factor, 2),
                'diezmo' => round((float) ($event->ganancia_diezmo ?? 0) * $factor, 2),
                'ofrenda' => round((float) ($event->ganancia_ofrenda ?? 0) * $factor, 2),
                'club' => round((float) ($event->ganancia_club ?? 0) * $factor, 2),
                'extras' => round((float) ($event->ganancia_extras ?? 0) * $factor, 2),
                'capital' => round((float) ($event->capital ?? 0) * $factor, 2),
            ];
            foreach ($parts as $key => $value) {
                $totales[$key] += $value;
            }

            $actividades[] = [
                'id' => (int) $event->id,
                'nombre' => $event->name,
                'starts_at' => $event->starts_at?->toIso8601String(),
                'abonado' => $abonado,
                'proyeccion' => $proyeccion,
                ...$parts,
            ];
        }

        $suma = array_sum($totales);
        $labels = [
            'participantes' => 'Participantes (ganancia)',
            'diezmo' => 'Diezmos',
            'ofrenda' => 'Ofrendas',
            'club' => 'Club (ganancia)',
            'extras' => 'Otros',
            'capital' => 'Capital / costos',
        ];
        $tones = [
            'participantes' => 'ok',
            'diezmo' => 'gold',
            'ofrenda' => 'danger',
            'club' => 'info',
            'extras' => 'extras',
            'capital' => 'muted',
        ];

        $partes = [];
        foreach ($totales as $key => $amount) {
            $partes[$key] = [
                'key' => $key,
                'label' => $labels[$key],
                'amount' => round($amount, 2),
                'percent' => $suma > 0 ? round(($amount / $suma) * 100, 1) : 0,
                'tone' => $tones[$key],
            ];
        }

        return ['partes' => $partes, 'actividades' => $actividades];
    }

    /**
     * @param  Collection<int, ClubesCajaMovimiento>  $movimientos
     * @return list<array<string, mixed>>
     */
    private function serieMensual(Collection $movimientos, Carbon $from, Carbon $to): array
    {
        $cursor = $from->copy()->startOfMonth();
        $end = $to->copy()->startOfMonth();
        $grouped = $movimientos->groupBy(fn (ClubesCajaMovimiento $row) => $row->occurred_at?->format('Y-m') ?? '');
        $out = [];

        while ($cursor <= $end) {
            $key = $cursor->format('Y-m');
            $rows = $grouped->get($key, collect());
            $out[] = [
                'mes' => $key,
                'label' => $cursor->locale('es')->isoFormat('MMM'),
                'ingresos' => round((float) $rows->where('tipo', ClubesCajaMovimiento::TIPO_INGRESO)->sum('monto'), 2),
                'gastos' => round((float) $rows->where('tipo', ClubesCajaMovimiento::TIPO_EGRESO)->sum('monto'), 2),
            ];
            $cursor->addMonth();
        }

        return $out;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function saldosCuentas(int $orgId, Carbon $from, Carbon $to, ?int $cuentaBancariaId): array
    {
        $cuentas = ClubesCajaCuenta::query()
            ->where('organizacion_id', $orgId)
            ->where('activa', true)
            ->orderBy('orden')
            ->get();

        $movimientos = ClubesCajaMovimiento::query()
            ->where('organizacion_id', $orgId)
            ->whereBetween('occurred_at', [$from, $to])
            ->tap(fn ($query) => $this->applyCuentaBancariaScope($query, $orgId, $cuentaBancariaId))
            ->tap(fn ($query) => $this->excludeCancelledEvents($query))
            ->get()
            ->groupBy(fn (ClubesCajaMovimiento $row) => (int) $row->cuenta_id);

        return $cuentas->map(function (ClubesCajaCuenta $cuenta) use ($movimientos) {
            $rows = $movimientos->get((int) $cuenta->id, collect());
            $saldo = round((float) $rows->sum(fn (ClubesCajaMovimiento $row) => $row->signedAmount()), 2);

            return [
                'id' => (int) $cuenta->id,
                'nombre' => $cuenta->nombre,
                'tipo' => $cuenta->tipo,
                'saldo' => $saldo,
            ];
        })->values()->all();
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function opcionesEventos(int $orgId): array
    {
        $events = Event::query()
            ->with(['gananciaEclesiastica.distribucion'])
            ->where('organizacion_id', $orgId)
            ->whereNull('evento_padre_id')
            ->tap(fn ($query) => $this->whereEventNotCancelled($query))
            ->whereHas('tipoEvento', fn ($tipo) => $tipo->where('slug', TipoEvento::SLUG_ACTIVIDAD_ECONOMICA))
            ->orderByDesc('starts_at')
            ->get([
                'id',
                'name',
                'starts_at',
                'estado',
                'capital',
                'ganancia_diezmo',
                'ganancia_ofrenda',
                'ganancia_eclesiastica_id',
            ]);

        $recogido = (new EventoGananciaRegistro())->recogidoPorEventos($events);

        return $events
            ->map(function (Event $event) use ($recogido) {
                $eventoId = (int) $event->id;
                $caja = $recogido[$eventoId] ?? ['capital' => 0.0, 'diezmo' => 0.0, 'ofrenda' => 0.0];

                return [
                    'id' => $eventoId,
                    'nombre' => $event->name,
                    'starts_at' => $event->starts_at?->toIso8601String(),
                    'capital' => round((float) ($event->capital ?? 0), 2),
                    'diezmo' => round((float) ($event->ganancia_diezmo ?? 0), 2),
                    'ofrenda' => round((float) ($event->ganancia_ofrenda ?? 0), 2),
                    'capital_recogido' => $caja['capital'],
                    'diezmo_recogido' => $caja['diezmo'],
                    'ofrenda_recogido' => $caja['ofrenda'],
                ];
            })
            ->values()
            ->all();
    }

    /**
     * Cuentas bancarias del club para filtrar y para registrar de dónde sale o entra el dinero.
     *
     * @return list<array<string, mixed>>
     */
    private function opcionesCuentasBancariasUsadas(int $orgId): array
    {
        $cuentas = CuentaBancaria::query()
            ->where('organizacion_id', $orgId)
            ->where('activo', true)
            ->orderBy('orden')
            ->orderBy('nombre')
            ->get();

        return $cuentas
            ->map(fn (CuentaBancaria $cuenta) => [
                'id' => (int) $cuenta->id,
                'nombre' => $this->etiquetaCuentaBancaria($cuenta),
                'banco' => $cuenta->banco,
                'numero_cuenta' => $cuenta->numero_cuenta,
            ])
            ->values()
            ->all();
    }

    private function etiquetaCuentaBancaria(CuentaBancaria $cuenta): string
    {
        $nombre = trim((string) ($cuenta->nombre ?: $cuenta->banco ?: 'Cuenta bancaria'));
        $numero = trim((string) $cuenta->numero_cuenta);

        return $numero !== '' ? "{$nombre} · {$numero}" : $nombre;
    }

    private function applyCuentaBancariaScope($query, int $orgId, ?int $cuentaBancariaId)
    {
        if (! $cuentaBancariaId) {
            return $query;
        }

        return $query->where(function ($inner) use ($orgId, $cuentaBancariaId) {
            $inner->where('cuenta_bancaria_id', $cuentaBancariaId)
                ->orWhere(function ($legacy) use ($orgId, $cuentaBancariaId) {
                    $legacy->whereNull('cuenta_bancaria_id')
                        ->whereHas(
                            'evento',
                            fn ($evento) => $this->whereEventoDeCuentaBancaria($evento, $orgId, $cuentaBancariaId),
                        );
                });
        });
    }

    private function whereEventoDeCuentaBancaria($query, int $orgId, int $cuentaBancariaId)
    {
        return $query->where(function ($inner) use ($orgId, $cuentaBancariaId) {
            $inner->where('cuenta_bancaria_id', $cuentaBancariaId);
            if (! $this->orgHasAssignedCuentas($orgId)) {
                $inner->orWhereNull('cuenta_bancaria_id');
            }
        });
    }

    private function orgHasAssignedCuentas(int $orgId): bool
    {
        return $this->orgHasAssignedCuentas ??= Event::query()
            ->where('organizacion_id', $orgId)
            ->whereNull('evento_padre_id')
            ->whereNotNull('cuenta_bancaria_id')
            ->tap(fn ($query) => $this->whereEventNotCancelled($query))
            ->whereHas('tipoEvento', fn ($tipo) => $tipo->where('slug', TipoEvento::SLUG_ACTIVIDAD_ECONOMICA))
            ->exists();
    }

    /**
     * @return array<string, mixed>
     */
    private function excludeCancelledEvents($query)
    {
        return $query->where(function ($inner) {
            $inner->whereNull('evento_id')
                ->orWhereHas('evento', fn ($evento) => $this->whereEventNotCancelled($evento));
        });
    }

    private function whereEventNotCancelled($query)
    {
        return $query->whereRaw('LOWER(estado) != ?', [Event::ESTADO_CANCELADO]);
    }

    private function eventIsCancelled(?Event $event): bool
    {
        return $event !== null && strcasecmp((string) $event->estado, Event::ESTADO_CANCELADO) === 0;
    }

    private function presentMovimiento(ClubesCajaMovimiento $row): array
    {
        return [
            'id' => (int) $row->id,
            'tipo' => $row->tipo,
            'origen' => $row->origen,
            'monto' => (float) $row->monto,
            'concepto' => $row->concepto,
            'cuenta_id' => (int) $row->cuenta_id,
            'cuenta_bancaria_id' => $row->cuenta_bancaria_id !== null ? (int) $row->cuenta_bancaria_id : null,
            'cuenta_nombre' => $row->cuentaBancaria
                ? $this->etiquetaCuentaBancaria($row->cuentaBancaria)
                : $row->cuenta?->nombre,
            'evento_id' => $row->evento_id !== null ? (int) $row->evento_id : null,
            'evento_nombre' => $row->evento?->name,
            'occurred_at' => $row->occurred_at?->toIso8601String(),
        ];
    }

    private function conceptoAbono(EventoParticipacionAbono $abono): string
    {
        $evento = $abono->evento?->name ?? 'Actividad';
        $producto = $abono->producto?->nombre;

        return $producto ? "Abono · {$evento} · {$producto}" : "Abono · {$evento}";
    }

    private function cuentaBancariaIdDelEvento(?Event $event, int $orgId): ?int
    {
        if ($event) {
            $event->loadMissing('padre');
            $id = (int) ($event->cuenta_bancaria_id ?: $event->padre?->cuenta_bancaria_id ?: 0);
            if ($id > 0) {
                return $id;
            }
        }

        $fallback = CuentaBancaria::query()
            ->where('organizacion_id', $orgId)
            ->where('activo', true)
            ->orderBy('orden')
            ->orderBy('id')
            ->value('id');

        return $fallback ? (int) $fallback : null;
    }

    private function cajaCuentaParaMovimiento(int $orgId, ?int $cuentaBancariaId): ?ClubesCajaCuenta
    {
        if ($cuentaBancariaId) {
            return $this->cuentaPorTipo($orgId, ClubesCajaCuenta::TIPO_BANCO)
                ?? $this->cuentaPorTipo($orgId, ClubesCajaCuenta::TIPO_CAJA);
        }

        return $this->cuentaPorTipo($orgId, ClubesCajaCuenta::TIPO_CAJA);
    }

    private function cuentaBancariaDeOrg(int $orgId, int $cuentaBancariaId): ?CuentaBancaria
    {
        if ($cuentaBancariaId <= 0) {
            return null;
        }

        return CuentaBancaria::query()
            ->where('organizacion_id', $orgId)
            ->where('id', $cuentaBancariaId)
            ->where('activo', true)
            ->first();
    }

    private function cuentaPorTipo(int $orgId, string $tipo): ?ClubesCajaCuenta
    {
        return ClubesCajaCuenta::query()
            ->where('organizacion_id', $orgId)
            ->where('tipo', $tipo)
            ->where('activa', true)
            ->orderBy('orden')
            ->first();
    }

    private function assertCanView(User $actor): int
    {
        $orgId = AppSetting::resolveOrganizacionId();
        abort_unless($orgId, Response::HTTP_FORBIDDEN, 'Debes tener una organización activa.');
        abort_unless(
            $this->actorManagesFinanzas($actor) || $actor->hasPermission('finanzas.view') || $actor->hasPermission('abonos.view'),
            Response::HTTP_FORBIDDEN,
            'No puedes ver las finanzas del club.',
        );

        return $orgId;
    }

    private function assertCanUpdate(User $actor): int
    {
        $orgId = $this->assertCanView($actor);
        abort_unless(
            $this->actorManagesFinanzas($actor) || $actor->hasPermission('finanzas.update'),
            Response::HTTP_FORBIDDEN,
            'No puedes registrar movimientos de caja.',
        );

        return $orgId;
    }

    private function actorManagesFinanzas(User $actor): bool
    {
        return $actor->isSuperAdmin() || count(array_intersect($actor->roleNames(), [
            'director',
            'subdirector',
            'secretario',
            'tesorero',
        ])) > 0;
    }

    private function tablesReady(): bool
    {
        return DB::getSchemaBuilder()->hasTable('clubes_caja_movimientos')
            && DB::getSchemaBuilder()->hasTable('clubes_caja_cuentas');
    }
}
