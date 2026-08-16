<?php

namespace App\Modules\Events\Services;

use App\Modules\Events\Models\Event;
use App\Modules\Events\Models\EventoProductoServicio;
use App\Modules\Events\Models\ProductoServicio;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

final class ProductoServicioService
{
    public function listCatalog(bool $soloActivos = true): Collection
    {
        $q = ProductoServicio::query()->orderBy('tipo')->orderBy('nombre');
        if ($soloActivos) {
            $q->where('activo', true);
        }

        return $q->get();
    }

    public function createProducto(array $data): ProductoServicio
    {
        return ProductoServicio::query()->create([
            'nombre' => $data['nombre'],
            'tipo' => strtoupper((string) $data['tipo']),
            'descripcion' => $data['descripcion'] ?? null,
            'precio' => $data['precio'] ?? 0,
            'unidad' => $data['unidad'] ?? 'UNIDAD',
            'activo' => $data['activo'] ?? true,
        ]);
    }

    public function updateProducto(ProductoServicio $producto, array $data): ProductoServicio
    {
        $producto->update(array_filter([
            'nombre' => $data['nombre'] ?? null,
            'tipo' => isset($data['tipo']) ? strtoupper((string) $data['tipo']) : null,
            'descripcion' => $data['descripcion'] ?? null,
            'precio' => $data['precio'] ?? null,
            'unidad' => $data['unidad'] ?? null,
            'activo' => $data['activo'] ?? null,
        ], fn ($v) => $v !== null));

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

    public function syncOfertasEvento(Event $evento, array $items): Collection
    {
        $keep = [];
        foreach ($items as $item) {
            $productoId = (int) ($item['producto_servicio_id'] ?? 0);
            if (! $productoId) {
                continue;
            }
            if (! ProductoServicio::query()->whereKey($productoId)->exists()) {
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
                    'precio' => $item['precio'] ?? 0,
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
