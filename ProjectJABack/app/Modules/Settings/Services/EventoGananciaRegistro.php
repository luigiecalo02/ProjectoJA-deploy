<?php

namespace App\Modules\Settings\Services;

use App\Modules\Events\Models\Event;
use App\Modules\Events\Models\EventoParticipacionVenta;
use App\Modules\Events\Models\EventoProductoServicio;
use App\Modules\Settings\Models\ClubesGananciaEclesiastica;

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
        $proyeccion = 0.0;
        $capital = 0.0;
        foreach ($ofertas as $oferta) {
            $productoId = (int) $oferta->producto_servicio_id;
            $cantidad = max(0, (int) ($cantidades[$productoId] ?? $oferta->cantidad_proyectada ?? 0));
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

        $ganancia = round($proyeccion - $capital, 2);
        $base = max(0, $ganancia);
        $ofrenda = $config ? $this->share($base, (float) $config->ofrenda) : 0;
        $diezmo = $config ? $this->share($base, (float) $config->diezmo) : 0;
        $resto = max(0, (int) round($base) - $ofrenda - $diezmo);
        $event->update([
            'capital' => round($capital, 2),
            'ganancia_proyeccion' => round($proyeccion, 2),
            'ganancia_proyectada' => $ganancia,
            'ganancia_ofrenda' => $ofrenda,
            'ganancia_diezmo' => $diezmo,
            'ganancia_resto' => $resto,
            'ganancia_participantes' => $dist ? $this->share($resto, (float) $dist->miembros) : $resto,
            'ganancia_club' => $dist ? $this->share($resto, (float) $dist->club) : 0,
            'ganancia_extras' => $dist ? $this->share($resto, (float) $dist->extras) : 0,
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
     * Parte de la ganancia bruta que corresponde a los participantes
     * después de diezmo y ofrenda.
     *
     * @param  array{ofrenda: float, diezmo: float, resto: float, miembros: float, club: float, extras: float}|null  $reparto
     */
    public function parteParticipante(float $gananciaBruta, ?array $reparto): int
    {
        $base = max(0, $gananciaBruta);
        if ($reparto === null) {
            return (int) round($base);
        }
        $ofrenda = $this->share($base, (float) $reparto['ofrenda']);
        $diezmo = $this->share($base, (float) $reparto['diezmo']);
        $resto = max(0, $base - $ofrenda - $diezmo);

        return $this->share($resto, (float) $reparto['miembros']);
    }

    private function share(float $total, float $percent): int
    {
        return (int) round((max(0, $total) * max(0, $percent)) / 100);
    }
}
