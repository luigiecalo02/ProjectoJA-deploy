<?php

namespace App\Modules\Settings\Services;

use App\Models\User;
use App\Modules\Events\Models\Event;
use App\Modules\Events\Models\EventoParticipacionAbono;
use App\Modules\Events\Models\TipoEvento;
use App\Modules\Settings\Models\AppSetting;
use App\Modules\Settings\Models\ClubesCajaCuenta;
use App\Modules\Settings\Models\ClubesCajaMovimiento;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response;

final class ClubesFinanzasService
{
    /**
     * @return array<string, mixed>
     */
    public function resumen(User $actor, ?string $desde, ?string $hasta, ?int $eventoId): array
    {
        $orgId = $this->assertCanView($actor);
        $this->ensureCuentas($orgId);
        $this->syncAbonosPendientes($orgId);

        $from = $desde ? Carbon::parse($desde)->startOfDay() : now()->startOfYear();
        $to = $hasta ? Carbon::parse($hasta)->endOfDay() : now()->endOfDay();

        $movimientos = ClubesCajaMovimiento::query()
            ->with(['cuenta', 'evento'])
            ->where('organizacion_id', $orgId)
            ->whereBetween('occurred_at', [$from, $to])
            ->when($eventoId, fn ($query) => $query->where('evento_id', $eventoId))
            ->orderByDesc('occurred_at')
            ->orderByDesc('id')
            ->get();

        $ingresos = round((float) $movimientos->where('tipo', ClubesCajaMovimiento::TIPO_INGRESO)->sum('monto'), 2);
        $gastos = round((float) $movimientos->where('tipo', ClubesCajaMovimiento::TIPO_EGRESO)->sum('monto'), 2);
        $disponible = round($ingresos - $gastos, 2);

        $distribucion = $this->distribucionActividades($orgId, $from, $to, $eventoId);
        $serie = $this->serieMensual($movimientos, $from, $to);
        $cuentas = $this->saldosCuentas($orgId, $from, $to, $eventoId);
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
            'evento_id' => $eventoId,
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
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function storeMovimiento(User $actor, array $data): array
    {
        $orgId = $this->assertCanUpdate($actor);
        $this->ensureCuentas($orgId);

        $cuentaId = (int) ($data['cuenta_id'] ?? 0);
        $cuenta = ClubesCajaCuenta::query()
            ->where('organizacion_id', $orgId)
            ->where('id', $cuentaId)
            ->where('activa', true)
            ->firstOrFail();

        $movimiento = ClubesCajaMovimiento::query()->create([
            'organizacion_id' => $orgId,
            'cuenta_id' => $cuenta->id,
            'tipo' => $data['tipo'],
            'origen' => $data['origen'] ?? ClubesCajaMovimiento::ORIGEN_MANUAL,
            'monto' => round((float) $data['monto'], 2),
            'concepto' => trim((string) $data['concepto']),
            'evento_id' => isset($data['evento_id']) ? (int) $data['evento_id'] : null,
            'registrado_por' => $actor->id,
            'occurred_at' => isset($data['occurred_at'])
                ? Carbon::parse($data['occurred_at'])
                : now(),
        ]);

        return $this->presentMovimiento($movimiento->load(['cuenta', 'evento']));
    }

    public function registrarAbonoAprobado(EventoParticipacionAbono $abono): void
    {
        if (! $this->tablesReady()) {
            return;
        }

        $orgId = (int) $abono->organizacion_id;
        $this->ensureCuentas($orgId);
        $cuenta = $this->cuentaPorTipo($orgId, ClubesCajaCuenta::TIPO_CAJA);
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
                'occurred_at' => $abono->updated_at ?? now(),
            ]);

            return;
        }

        ClubesCajaMovimiento::query()->create([
            'organizacion_id' => $orgId,
            'cuenta_id' => $cuenta->id,
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

        $orgId = (int) $abono->organizacion_id;
        $this->ensureCuentas($orgId);
        $cuenta = $this->cuentaPorTipo($orgId, ClubesCajaCuenta::TIPO_CAJA);
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
            ->where('organizacion_id', $orgId)
            ->where(function ($query) {
                $query->where('estado', EventoParticipacionAbono::ESTADO_APROBADO)
                    ->orWhereNull('estado');
            })
            ->when($synced !== [], fn ($query) => $query->whereNotIn('id', $synced))
            ->orderBy('id')
            ->each(fn (EventoParticipacionAbono $abono) => $this->registrarAbonoAprobado($abono));
    }

    /**
     * @return array{partes: array<string, array<string, mixed>>, actividades: list<array<string, mixed>>}
     */
    private function distribucionActividades(int $orgId, Carbon $from, Carbon $to, ?int $eventoId): array
    {
        $events = Event::query()
            ->where('organizacion_id', $orgId)
            ->whereNull('evento_padre_id')
            ->where('estado', '!=', Event::ESTADO_CANCELADO)
            ->whereHas('tipoEvento', fn ($tipo) => $tipo->where('slug', TipoEvento::SLUG_ACTIVIDAD_ECONOMICA))
            ->when($eventoId, fn ($query) => $query->where('id', $eventoId))
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
            if ($abonado <= 0 && ! $eventoId) {
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
    private function saldosCuentas(int $orgId, Carbon $from, Carbon $to, ?int $eventoId): array
    {
        $cuentas = ClubesCajaCuenta::query()
            ->where('organizacion_id', $orgId)
            ->where('activa', true)
            ->orderBy('orden')
            ->get();

        $movimientos = ClubesCajaMovimiento::query()
            ->where('organizacion_id', $orgId)
            ->whereBetween('occurred_at', [$from, $to])
            ->when($eventoId, fn ($query) => $query->where('evento_id', $eventoId))
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
        return Event::query()
            ->where('organizacion_id', $orgId)
            ->whereNull('evento_padre_id')
            ->where('estado', '!=', Event::ESTADO_CANCELADO)
            ->whereHas('tipoEvento', fn ($tipo) => $tipo->where('slug', TipoEvento::SLUG_ACTIVIDAD_ECONOMICA))
            ->orderByDesc('starts_at')
            ->get(['id', 'name', 'starts_at'])
            ->map(fn (Event $event) => [
                'id' => (int) $event->id,
                'nombre' => $event->name,
                'starts_at' => $event->starts_at?->toIso8601String(),
            ])
            ->values()
            ->all();
    }

    /**
     * @return array<string, mixed>
     */
    private function presentMovimiento(ClubesCajaMovimiento $row): array
    {
        return [
            'id' => (int) $row->id,
            'tipo' => $row->tipo,
            'origen' => $row->origen,
            'monto' => (float) $row->monto,
            'concepto' => $row->concepto,
            'cuenta_id' => (int) $row->cuenta_id,
            'cuenta_nombre' => $row->cuenta?->nombre,
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
