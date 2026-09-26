<?php

namespace App\Modules\Events\Services;

use App\Modules\Events\Models\Event;
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
            $oferta = EventoProductoServicio::query()->updateOrCreate(
                [
                    'evento_id' => $evento->id,
                    'producto_servicio_id' => $productoId,
                ],
                [
                    'precio' => $item['precio'] ?? $producto->precio ?? 0,
                    'activo' => $item['activo'] ?? true,
                ]
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
