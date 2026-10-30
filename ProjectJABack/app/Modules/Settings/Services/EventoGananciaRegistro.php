<?php

namespace App\Modules\Settings\Services;

use App\Modules\Events\Models\Event;
use App\Modules\Events\Models\EventoParticipacionAbono;
use App\Modules\Events\Models\EventoParticipacionVenta;
use App\Modules\Events\Models\EventoProductoServicio;
use App\Modules\Settings\Models\ClubesGananciaEclesiastica;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

final class EventoGananciaRegistro
{
    /**
     * @var array<int, array{ofrenda: float, diezmo: float, resto: float, miembros: float, club: float, extras: float}|null>
     */
    private array $repartos = [];

    /**
     * @return array<string, float>
     */
    public function registrar(Event $event): array
    {
        $config = $event->ganancia_eclesiastica_id
            ? ClubesGananciaEclesiastica::query()->with('distribucion')->find($event->ganancia_eclesiastica_id)
            : null;
        $dist = $config?->distribucion;
        $ofertas = EventoProductoServicio::query()
            ->where('evento_id', $event->id)
            ->where('activo', true)
            ->get();

        $cantidades = EventoParticipacionVenta::totalesPorProducto(
            (int) $event->id,
            $event->cuentaUnidadesEntregadas(),
        );
        $usarEntregadas = $event->cuentaUnidadesEntregadas();
        $proyeccion = 0.0;
        $capital = 0.0;
        foreach ($ofertas as $oferta) {
            $productoId = (int) $oferta->producto_servicio_id;
            $cantidad = max(0, (int) ($cantidades[$productoId] ?? 0));
            if ($cantidad === 0 && ! $usarEntregadas) {
                $cantidad = max(0, (int) ($oferta->cantidad_proyectada ?? 0));
            }
            $total = round($cantidad * (float) $oferta->precio, 2);
            $neta = $total - (float) ($oferta->inversion ?? 0);
            $baseLinea = max(0, $neta);
            $paraParticipantes = $dist
                ? $this->share($baseLinea, (float) $dist->miembros)
                : (int) round($baseLinea);
            $oferta->update([
                'total' => $total,
                'ganancia_unidad' => $cantidad > 0 ? (int) round($paraParticipantes / $cantidad) : 0,
            ]);
            $proyeccion += $total;
            $capital += (float) ($oferta->inversion ?? 0);
        }

        $snap = $this->repartirGanancia($event, $proyeccion, $capital);
        $event->update([
            'capital' => $snap['capital'],
            'ganancia_proyeccion' => $snap['proyeccion'],
            'ganancia_proyectada' => $snap['ganancia'],
            'ganancia_ofrenda' => $snap['ofrenda'],
            'ganancia_diezmo' => $snap['diezmo'],
            'ganancia_resto' => $snap['resto'],
            'ganancia_participantes' => $snap['participantes'],
            'ganancia_club' => $snap['club'],
            'ganancia_extras' => $snap['extras'],
        ]);

        return self::presentar($event->fresh() ?? $event);
    }

    public function registrarConfiguracion(int $eclesiasticaId): void
    {
        Event::query()
            ->where('ganancia_eclesiastica_id', $eclesiasticaId)
            ->orderBy('id')
            ->each(fn (Event $event) => $this->registrar($event));
    }

    public function registrarDistribucion(int $distribucionId): void
    {
        $ids = ClubesGananciaEclesiastica::query()
            ->where('distribucion_id', $distribucionId)
            ->pluck('id');
        Event::query()
            ->whereIn('ganancia_eclesiastica_id', $ids)
            ->orderBy('id')
            ->each(fn (Event $event) => $this->registrar($event));
    }

    /**
     * @return array<string, float>
     */
    public static function presentar(Event $event): array
    {
        return [
            'proyeccion' => round((float) ($event->ganancia_proyeccion ?? 0), 2),
            'capital' => round((float) ($event->capital ?? 0), 2),
            'ganancia' => round((float) ($event->ganancia_proyectada ?? 0), 2),
            'ofrenda' => round((float) ($event->ganancia_ofrenda ?? 0), 2),
            'diezmo' => round((float) ($event->ganancia_diezmo ?? 0), 2),
            'resto' => round((float) ($event->ganancia_resto ?? 0), 2),
            'participantes' => round((float) ($event->ganancia_participantes ?? 0), 2),
            'club' => round((float) ($event->ganancia_club ?? 0), 2),
            'extras' => round((float) ($event->ganancia_extras ?? 0), 2),
        ];
    }

    /**
     * @return array{ofrenda: float, diezmo: float, resto: float, miembros: float, club: float, extras: float}|null
     */
    public function repartoDe(?Event $event): ?array
    {
        $id = (int) ($event?->ganancia_eclesiastica_id ?? 0);
        if ($id <= 0) {
            return null;
        }
        if (array_key_exists($id, $this->repartos)) {
            return $this->repartos[$id];
        }

        $config = $event instanceof Event && $event->relationLoaded('gananciaEclesiastica')
            ? $event->gananciaEclesiastica
            : ClubesGananciaEclesiastica::query()->with('distribucion')->find($id);
        if (! $config instanceof ClubesGananciaEclesiastica) {
            return $this->repartos[$id] = null;
        }

        if (! $config->relationLoaded('distribucion')) {
            $config->load('distribucion');
        }
        $diezmo = (float) $config->diezmo;
        $ofrenda = (float) $config->ofrenda;
        $dist = $config->distribucion;

        return $this->repartos[$id] = [
            'ofrenda' => $ofrenda,
            'diezmo' => $diezmo,
            'resto' => round(100 - $diezmo - $ofrenda, 2),
            'miembros' => (float) ($dist?->miembros ?? 0),
            'club' => (float) ($dist?->club ?? 0),
            'extras' => (float) ($dist?->extras ?? 0),
        ];
    }

    /**
     * Capital y ganancia ya cubiertos por abonos (cascada por persona y producto).
     *
     * @param  Collection<int, Event>  $events
     * @return array<int, array{abonado: float, capital: float, diezmo: float, ofrenda: float, participantes: float, club: float, extras: float}>
     */
    public function recogidoPorEventos(Collection $events, ?Carbon $from = null, ?Carbon $to = null): array
    {
        $ids = $events->map(fn (Event $event) => (int) $event->id)->all();
        $vacio = [
            'abonado' => 0.0,
            'capital' => 0.0,
            'diezmo' => 0.0,
            'ofrenda' => 0.0,
            'participantes' => 0.0,
            'club' => 0.0,
            'extras' => 0.0,
        ];
        $out = [];
        foreach ($ids as $id) {
            $out[$id] = $vacio;
        }
        if ($ids === []) {
            return $out;
        }

        $ofertas = EventoProductoServicio::query()
            ->whereIn('evento_id', $ids)
            ->where('activo', true)
            ->get()
            ->groupBy(fn (EventoProductoServicio $row) => (int) $row->evento_id);

        $ventas = EventoParticipacionVenta::query()
            ->join(
                'evento_participacion',
                'evento_participacion.id',
                '=',
                'evento_participacion_venta.evento_participacion_id',
            )
            ->whereIn('evento_participacion.evento_id', $ids)
            ->where('evento_participacion.participa', true)
            ->get([
                'evento_participacion.evento_id',
                'evento_participacion.persona_id',
                'evento_participacion_venta.producto_servicio_id',
                'evento_participacion_venta.cantidad',
                'evento_participacion_venta.cantidad_entregada',
            ])
            ->groupBy(fn (EventoParticipacionVenta $row) => (int) $row->evento_id);

        $pagado = [];
        EventoParticipacionAbono::query()
            ->whereIn('evento_id', $ids)
            ->when($from && $to, fn ($query) => $query->whereBetween('created_at', [$from, $to]))
            ->get()
            ->each(function (EventoParticipacionAbono $abono) use (&$pagado): void {
                $monto = $abono->montoContable();
                if ($monto <= 0) {
                    return;
                }
                $eventoId = (int) $abono->evento_id;
                $personaId = (int) $abono->persona_id;
                $productoId = (int) $abono->producto_servicio_id;
                $pagado[$eventoId][$personaId][$productoId] = ($pagado[$eventoId][$personaId][$productoId] ?? 0) + $monto;
            });

        foreach ($events as $event) {
            $eventoId = (int) $event->id;
            $reparto = $this->repartoDe($event);
            $listaVentas = $ventas->get($eventoId, collect());
            $totales = [];
            foreach ($listaVentas as $venta) {
                $productoId = (int) $venta->producto_servicio_id;
                $totales[$productoId] = ($totales[$productoId] ?? 0) + $venta->cantidadContable();
            }

            $capitalUnidad = [];
            foreach ($ofertas->get($eventoId, collect()) as $oferta) {
                $productoId = (int) $oferta->producto_servicio_id;
                $proyectada = (int) ($totales[$productoId] ?? $oferta->cantidad_proyectada ?? 0);
                $inversion = (float) ($oferta->inversion ?? 0);
                $capitalUnidad[$productoId] = $proyectada > 0 ? (int) round($inversion / $proyectada) : 0;
            }

            $capital = 0.0;
            $diezmo = 0.0;
            $ofrenda = 0.0;
            $participantes = 0.0;
            $club = 0.0;
            $extras = 0.0;
            $abonadoTotal = 0.0;
            foreach ($pagado[$eventoId] ?? [] as $porPersona) {
                $abonadoTotal += array_sum($porPersona);
            }
            foreach ($listaVentas as $venta) {
                $productoId = (int) $venta->producto_servicio_id;
                $qty = $venta->cantidadContable();
                if ($qty <= 0) {
                    continue;
                }
                $costo = $qty * ($capitalUnidad[$productoId] ?? 0);
                $abonado = (float) ($pagado[$eventoId][(int) $venta->persona_id][$productoId] ?? 0);
                $cubierto = min($abonado, $costo);
                $ganancia = max(0, $abonado - $costo);
                $partes = $this->partesDeGanancia($ganancia, $reparto);
                $capital += $cubierto;
                $diezmo += $partes['diezmo'];
                $ofrenda += $partes['ofrenda'];
                $participantes += $partes['participantes'];
                $club += $partes['club'];
                $extras += $partes['extras'];
            }

            $out[$eventoId] = [
                'abonado' => round($abonadoTotal, 2),
                'capital' => round($capital, 2),
                'diezmo' => round($diezmo, 2),
                'ofrenda' => round($ofrenda, 2),
                'participantes' => round($participantes, 2),
                'club' => round($club, 2),
                'extras' => round($extras, 2),
            ];
        }

        return $out;
    }

    /**
     * Reparto planteado según unidades entregadas (o el pedido si aún no hay entrega).
     * No usa la cantidad proyectada del servicio.
     *
     * @param  Collection<int, Event>  $events
     * @return array<int, array{proyeccion: float, capital: float, diezmo: float, ofrenda: float, participantes: float, club: float, extras: float}>
     */
    public function proyectadoPorEventos(Collection $events): array
    {
        $ids = $events->map(fn (Event $event) => (int) $event->id)->all();
        $vacio = [
            'proyeccion' => 0.0,
            'capital' => 0.0,
            'diezmo' => 0.0,
            'ofrenda' => 0.0,
            'participantes' => 0.0,
            'club' => 0.0,
            'extras' => 0.0,
        ];
        $out = [];
        foreach ($ids as $id) {
            $out[$id] = $vacio;
        }
        if ($ids === []) {
            return $out;
        }

        $ofertas = EventoProductoServicio::query()
            ->whereIn('evento_id', $ids)
            ->where('activo', true)
            ->get()
            ->groupBy(fn (EventoProductoServicio $row) => (int) $row->evento_id);

        $ventas = EventoParticipacionVenta::query()
            ->join(
                'evento_participacion',
                'evento_participacion.id',
                '=',
                'evento_participacion_venta.evento_participacion_id',
            )
            ->whereIn('evento_participacion.evento_id', $ids)
            ->where('evento_participacion.participa', true)
            ->get([
                'evento_participacion.evento_id',
                'evento_participacion_venta.producto_servicio_id',
                'evento_participacion_venta.cantidad',
                'evento_participacion_venta.cantidad_entregada',
            ])
            ->groupBy(fn (EventoParticipacionVenta $row) => (int) $row->evento_id);

        foreach ($events as $event) {
            $eventoId = (int) $event->id;
            $listaVentas = $ventas->get($eventoId, collect());
            $hayEntregas = $listaVentas->contains(
                fn (EventoParticipacionVenta $venta) => $venta->cantidad_entregada !== null,
            );
            $cantidades = [];
            foreach ($listaVentas as $venta) {
                $productoId = (int) $venta->producto_servicio_id;
                $qty = $hayEntregas
                    ? (int) ($venta->cantidad_entregada ?? 0)
                    : (int) $venta->cantidad;
                $cantidades[$productoId] = ($cantidades[$productoId] ?? 0) + max(0, $qty);
            }
            $proyeccion = 0.0;
            $capital = 0.0;
            foreach ($ofertas->get($eventoId, collect()) as $oferta) {
                $productoId = (int) $oferta->producto_servicio_id;
                $cantidad = max(0, (int) ($cantidades[$productoId] ?? 0));
                $proyeccion += round($cantidad * (float) $oferta->precio, 2);
                $capital += (float) ($oferta->inversion ?? 0);
            }
            $snap = $this->repartirGanancia($event, $proyeccion, $capital);
            $out[$eventoId] = [
                'proyeccion' => $snap['proyeccion'],
                'capital' => $snap['capital'],
                'diezmo' => $snap['diezmo'],
                'ofrenda' => $snap['ofrenda'],
                'participantes' => $snap['participantes'],
                'club' => $snap['club'],
                'extras' => $snap['extras'],
            ];
        }

        return $out;
    }

    /**
     * Parte de la ganancia bruta que corresponde a los participantes
     * después de diezmo y ofrenda.
     *
     * @param  array{ofrenda: float, diezmo: float, resto: float, miembros: float, club: float, extras: float}|null  $reparto
     */
    public function parteParticipante(float $gananciaBruta, ?array $reparto): int
    {
        return (int) $this->partesDeGanancia($gananciaBruta, $reparto)['participantes'];
    }

    /**
     * @param  array{ofrenda: float, diezmo: float, resto: float, miembros: float, club: float, extras: float}|null  $reparto
     * @return array{ofrenda: float, diezmo: float, resto: float, participantes: float, club: float, extras: float}
     */
    public function partesDeGanancia(float $gananciaBruta, ?array $reparto): array
    {
        $base = max(0, $gananciaBruta);
        if ($reparto === null) {
            return [
                'ofrenda' => 0.0,
                'diezmo' => 0.0,
                'resto' => $base,
                'participantes' => round($base, 2),
                'club' => 0.0,
                'extras' => 0.0,
            ];
        }

        $ofrenda = $this->share($base, (float) $reparto['ofrenda']);
        $diezmo = $this->share($base, (float) $reparto['diezmo']);
        $resto = max(0, $base - $ofrenda - $diezmo);
        $participantes = $this->share($resto, (float) $reparto['miembros']);
        $tieneExtras = ((float) $reparto['extras']) > 0;
        $club = $tieneExtras ? $this->share($resto, (float) $reparto['club']) : $resto - $participantes;
        $extras = $tieneExtras ? max(0, $resto - $participantes - $club) : 0.0;

        return [
            'ofrenda' => $ofrenda,
            'diezmo' => $diezmo,
            'resto' => $resto,
            'participantes' => $participantes,
            'club' => $club,
            'extras' => $extras,
        ];
    }

    /**
     * @return array{
     *   proyeccion: float,
     *   capital: float,
     *   ganancia: float,
     *   ofrenda: float,
     *   diezmo: float,
     *   resto: float,
     *   participantes: float,
     *   club: float,
     *   extras: float
     * }
     */
    private function repartirGanancia(Event $event, float $proyeccion, float $capital): array
    {
        $ganancia = round($proyeccion - $capital, 2);
        $base = max(0, $ganancia);
        $reparto = $this->repartoDe($event);
        if ($reparto === null) {
            $resto = max(0, (int) round($base));

            return [
                'proyeccion' => round($proyeccion, 2),
                'capital' => round($capital, 2),
                'ganancia' => $ganancia,
                'ofrenda' => 0.0,
                'diezmo' => 0.0,
                'resto' => (float) $resto,
                'participantes' => (float) $resto,
                'club' => 0.0,
                'extras' => 0.0,
            ];
        }

        $ofrenda = $this->share($base, (float) $reparto['ofrenda']);
        $diezmo = $this->share($base, (float) $reparto['diezmo']);
        $resto = max(0, (int) round($base) - $ofrenda - $diezmo);

        return [
            'proyeccion' => round($proyeccion, 2),
            'capital' => round($capital, 2),
            'ganancia' => $ganancia,
            'ofrenda' => (float) $ofrenda,
            'diezmo' => (float) $diezmo,
            'resto' => (float) $resto,
            'participantes' => (float) $this->share($resto, (float) $reparto['miembros']),
            'club' => (float) $this->share($resto, (float) $reparto['club']),
            'extras' => (float) $this->share($resto, (float) $reparto['extras']),
        ];
    }

    private function share(float $total, float $percent): int
    {
        return (int) round((max(0, $total) * max(0, $percent)) / 100);
    }
}
