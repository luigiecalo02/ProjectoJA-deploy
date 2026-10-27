<?php

namespace App\Modules\Events\Services;

use App\Modules\Events\Models\Event;
use App\Modules\Events\Models\EventoParticipacionVenta;
use App\Modules\Events\Models\EventoProductoServicio;
use App\Modules\Events\Models\ProductoServicio;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

final class ProductoServicioService
{
    public function listCatalog(bool $soloActivos = true, ?int $organizacionId = null): Collection
    {
        $q = ProductoServicio::query()->orderBy('tipo')->orderBy('nombre');
        if ($soloActivos) {
            $q->where('activo', true);
        }
        if ($organizacionId !== null) {
            $q->forOrganizacion($organizacionId);
        }

        return $q->get();
    }

    public function createProducto(array $data): ProductoServicio
    {
        return ProductoServicio::query()->create([
            'organizacion_id' => $data['organizacion_id'] ?? null,
            'nombre' => $data['nombre'],
            'tipo' => strtoupper((string) ($data['tipo'] ?? ProductoServicio::TIPO_SERVICIO)),
            'descripcion' => $data['descripcion'] ?? null,
            'precio' => $data['precio'] ?? 0,
            'unidad' => $data['unidad'] ?? 'UNIDAD',
            'icono' => $data['icono'] ?? null,
            'activo' => $data['activo'] ?? true,
        ]);
    }

    public function updateProducto(ProductoServicio $producto, array $data): ProductoServicio
    {
        $fields = array_filter([
            'nombre' => $data['nombre'] ?? null,
            'tipo' => isset($data['tipo']) ? strtoupper((string) $data['tipo']) : null,
            'descripcion' => $data['descripcion'] ?? null,
            'precio' => $data['precio'] ?? null,
            'unidad' => $data['unidad'] ?? null,
            'activo' => $data['activo'] ?? null,
        ], fn ($v) => $v !== null);
        if (array_key_exists('icono', $data)) {
            $fields['icono'] = $data['icono'] ?: null;
        }

        $producto->update($fields);

        return $producto->fresh();
    }

    /**
     * @var array<int, array<int, array{precio: float, ganancia_unidad: float, inversion: float, cantidad_proyectada: int}>>
     */
    private array $ofertasActivas = [];

    /** @var array<int, array<int, int>> */
    private array $unidadesContables = [];

    public function precioDeOferta(int $eventoId, int $productoId, float $fallback = 0): float
    {
        return $this->ofertaActiva($eventoId, $productoId)['precio'] ?? $fallback;
    }

    public function gananciaUnidadDeOferta(int $eventoId, int $productoId): float
    {
        return $this->ofertaActiva($eventoId, $productoId)['ganancia_unidad'] ?? 0;
    }

    public function gananciaBrutaUnidadDeOferta(int $eventoId, int $productoId): float
    {
        $oferta = $this->ofertaActiva($eventoId, $productoId);
        if ($oferta === null) {
            return 0;
        }
        $unidades = $this->unidadesContables($eventoId)[$productoId]
            ?? (int) ($oferta['cantidad_proyectada'] ?? 0);
        $precio = (int) round((float) $oferta['precio']);
        $capitalUnidad = $unidades > 0 ? (int) round(((float) $oferta['inversion']) / $unidades) : 0;

        return (float) max(0, $precio - $capitalUnidad);
    }

    /**
     * @return array<int, int>
     */
    private function unidadesContables(int $eventoId): array
    {
        if (! array_key_exists($eventoId, $this->unidadesContables)) {
            $event = Event::query()->find($eventoId);
            $this->unidadesContables[$eventoId] = EventoParticipacionVenta::totalesPorProducto(
                $eventoId,
                $event instanceof Event && $event->cuentaUnidadesEntregadas(),
            );
        }

        return $this->unidadesContables[$eventoId];
    }

    /**
     * @return array{precio: float, ganancia_unidad: float, inversion: float, cantidad_proyectada: int}|null
     */
    private function ofertaActiva(int $eventoId, int $productoId): ?array
    {
        if (! array_key_exists($eventoId, $this->ofertasActivas)) {
            $this->ofertasActivas[$eventoId] = EventoProductoServicio::query()
                ->where('evento_id', $eventoId)
                ->where('activo', true)
                ->get(['producto_servicio_id', 'precio', 'ganancia_unidad', 'inversion', 'cantidad_proyectada'])
                ->mapWithKeys(fn (EventoProductoServicio $oferta) => [(int) $oferta->producto_servicio_id => [
                    'precio' => (float) $oferta->precio,
                    'ganancia_unidad' => (float) $oferta->ganancia_unidad,
                    'inversion' => (float) ($oferta->inversion ?? 0),
                    'cantidad_proyectada' => (int) ($oferta->cantidad_proyectada ?? 0),
                ]])
                ->all();
        }

        return $this->ofertasActivas[$eventoId][$productoId] ?? null;
    }

    public function listOfertasEvento(Event $evento): Collection
    {
        return EventoProductoServicio::query()
            ->with('producto')
            ->where('evento_id', $evento->id)
            ->orderBy('id')
            ->get();
    }

    public function syncOfertasEvento(
        Event $evento,
        array $items,
        ?int $organizacionId = null,
        bool $soloEconomica = false,
    ): Collection {
        if ($soloEconomica && ! $evento->isActividadEconomica()) {
            throw ValidationException::withMessages([
                'evento' => ['Los servicios solo se asocian a actividades económicas.'],
            ]);
        }

        $keep = [];
        foreach ($items as $item) {
            $productoId = (int) ($item['producto_servicio_id'] ?? 0);
            if (! $productoId) {
                continue;
            }
            $productoQuery = ProductoServicio::query()->whereKey($productoId);
            if ($organizacionId !== null) {
                $productoQuery->forOrganizacion($organizacionId);
            }
            $producto = $productoQuery->first();
            if (! $producto) {
                throw ValidationException::withMessages([
                    'productos' => ["Producto {$productoId} no existe."],
                ]);
            }
            $values = [
                'precio' => $item['precio'] ?? $producto->precio ?? 0,
                'activo' => $item['activo'] ?? true,
            ];
            if (array_key_exists('cantidad_proyectada', $item) && $item['cantidad_proyectada'] !== null) {
                $values['cantidad_proyectada'] = max(0, (int) $item['cantidad_proyectada']);
            }
            if (array_key_exists('inversion', $item) && $item['inversion'] !== null && $item['inversion'] !== '') {
                $values['inversion'] = round(max(0, (float) $item['inversion']), 2);
            }
            unset($this->ofertasActivas[$evento->id]);
            $oferta = EventoProductoServicio::query()->updateOrCreate(
                [
                    'evento_id' => $evento->id,
                    'producto_servicio_id' => $productoId,
                ],
                $values,
            );
            $keep[] = $oferta->id;
        }

        EventoProductoServicio::query()
            ->where('evento_id', $evento->id)
            ->when($keep !== [], fn ($q) => $q->whereNotIn('id', $keep))
            ->when($keep === [], fn ($q) => $q)
            ->update(['activo' => false]);

        return $this->listOfertasEvento($evento);
    }
}
