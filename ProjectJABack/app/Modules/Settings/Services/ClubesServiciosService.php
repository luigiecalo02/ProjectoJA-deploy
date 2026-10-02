<?php

namespace App\Modules\Settings\Services;

use App\Models\User;
use App\Modules\Events\Models\Event;
use App\Modules\Events\Models\EventoParticipacionAbono;
use App\Modules\Events\Models\EventoProductoServicio;
use App\Modules\Events\Models\Icono;
use App\Modules\Events\Models\ProductoServicio;
use App\Modules\Events\Services\IconoCatalogService;
use App\Modules\Events\Services\ProductoServicioService;
use App\Modules\Settings\Models\AppSetting;
use App\Modules\Shared\Services\ImageOptimizer;
use App\Modules\Shared\Services\PublicFileService;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\Response;

final class ClubesServiciosService
{
    public function __construct(
        private readonly ProductoServicioService $productos,
        private readonly IconoCatalogService $iconos,
        private readonly ImageOptimizer $images,
        private readonly PublicFileService $publicFiles,
    ) {}

    /**
     * @return list<array<string, mixed>>
     */
    public function list(User $actor, bool $soloActivos = false): array
    {
        $orgId = $this->assertCanView($actor);

        return $this->productos->listCatalog($soloActivos, $orgId)
            ->map(fn (ProductoServicio $producto) => $this->productoPayload($producto))
            ->values()
            ->all();
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function listIconos(User $actor): array
    {
        $this->assertCanView($actor);

        return collect($this->iconos->list())
            ->map(fn (Icono $icono) => [
                'id' => (int) $icono->id,
                'nombre' => $icono->nombre,
                'slug' => $icono->slug,
                'categoria' => $icono->categoria,
                'etiquetas' => array_values($icono->etiquetas ?? []),
                'tipo' => $icono->tipo,
                'valor' => $icono->valor,
                'url' => $icono->tipo === 'imagen' ? $this->publicFiles->url($icono->valor) : null,
            ])
            ->values()
            ->all();
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public function create(User $actor, array $data, ?UploadedFile $image = null): array
    {
        $orgId = $this->assertCanWrite($actor);

        $producto = $this->productos->createProducto([
            ...$data,
            'organizacion_id' => $orgId,
            'tipo' => $data['tipo'] ?? ProductoServicio::TIPO_SERVICIO,
        ]);
        if ($image) {
            $producto = $this->storeImage($producto, $image);
        }

        return $this->productoPayload($producto);
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public function update(
        User $actor,
        ProductoServicio $producto,
        array $data,
        ?UploadedFile $image = null,
        bool $removeImage = false,
    ): array {
        $orgId = $this->assertCanWrite($actor);
        $this->assertOwned($producto, $orgId);

        $producto = $this->productos->updateProducto($producto, $data);
        if ($removeImage && ! $image) {
            $this->deleteStoredImage($producto->image_path);
            $producto->update(['image_path' => null]);
            $producto = $producto->fresh() ?? $producto;
        }
        if ($image) {
            $producto = $this->storeImage($producto, $image);
        }

        return $this->productoPayload($producto);
    }

    public function delete(User $actor, ProductoServicio $producto): void
    {
        $orgId = $this->assertCanWrite($actor);
        $this->assertOwned($producto, $orgId);

        $enUso = EventoProductoServicio::query()
            ->where('producto_servicio_id', $producto->id)
            ->exists();
        if ($enUso) {
            throw ValidationException::withMessages([
                'servicio' => ['Este servicio está asociado a un evento. Desactívalo en lugar de eliminarlo.'],
            ]);
        }

        $this->deleteStoredImage($producto->image_path);
        $producto->delete();
    }

    /**
     * @return array<string, mixed>
     */
    public function listOfertas(User $actor, Event $event): array
    {
        $orgId = $this->assertCanView($actor);
        $this->assertVisibleEvent($actor, $event);

        return [
            'evento' => [
                'id' => (int) $event->id,
                'name' => $event->name,
                'es_economica' => $event->isActividadEconomica(),
                'tiene_abonos' => $this->eventoTieneAbonos($event),
            ],
            'catalogo' => $this->productos->listCatalog(true, $orgId)
                ->map(fn (ProductoServicio $producto) => $this->productoPayload($producto))
                ->values()
                ->all(),
            'ofertas' => $this->productos->listOfertasEvento($event)
                ->filter(fn (EventoProductoServicio $oferta) => (bool) $oferta->activo)
                ->map(fn (EventoProductoServicio $oferta) => $this->ofertaPayload($oferta))
                ->values()
                ->all(),
        ];
    }

    /**
     * @param  list<int>  $productoIds
     * @return array<string, mixed>
     */
    public function syncOfertas(User $actor, Event $event, array $productoIds): array
    {
        $orgId = $this->assertCanWrite($actor);
        $this->assertVisibleEvent($actor, $event);

        $nextIds = array_values(array_unique(array_map('intval', $productoIds)));
        $this->assertPuedeQuitarServicios($event, $nextIds);

        $catalog = $this->productos->listCatalog(false, $orgId)->keyBy('id');
        $items = [];
        foreach ($nextIds as $productoId) {
            $producto = $catalog->get($productoId);
            if (! $producto) {
                throw ValidationException::withMessages([
                    'producto_servicio_ids' => ['Hay servicios que no pertenecen a este club.'],
                ]);
            }
            $items[] = [
                'producto_servicio_id' => $productoId,
                'precio' => $producto->precio,
                'activo' => true,
            ];
        }

        $this->productos->syncOfertasEvento($event, $items, $orgId, true);

        return $this->listOfertas($actor, $event->fresh());
    }

    private function assertCanView(User $actor): int
    {
        $orgId = AppSetting::resolveOrganizacionId();
        abort_unless($orgId, Response::HTTP_FORBIDDEN, 'Debes tener una organización activa.');
        abort_unless(
            $actor->hasPermission('productos_servicios.view') || $this->actorManagesClub($actor),
            Response::HTTP_FORBIDDEN,
            'No puedes ver los servicios del club.',
        );

        return $orgId;
    }

    private function assertCanWrite(User $actor): int
    {
        $orgId = $this->assertCanView($actor);
        abort_unless(
            $actor->hasPermission('productos_servicios.create')
            || $actor->hasPermission('productos_servicios.update')
            || $this->actorManagesClub($actor),
            Response::HTTP_FORBIDDEN,
            'No puedes gestionar los servicios del club.',
        );

        return $orgId;
    }

    private function actorManagesClub(User $actor): bool
    {
        return count(array_intersect($actor->roleNames(), [
            'director',
            'subdirector',
            'secretario',
        ])) > 0;
    }

    private function assertOwned(ProductoServicio $producto, int $orgId): void
    {
        abort_unless(
            (int) $producto->organizacion_id === $orgId,
            Response::HTTP_NOT_FOUND,
            'Este servicio no pertenece al club.',
        );
    }

    private function eventoTieneAbonos(Event $event): bool
    {
        return EventoParticipacionAbono::query()
            ->where('evento_id', $event->id)
            ->exists();
    }

    /**
     * @param  list<int>  $nextIds
     */
    private function assertPuedeQuitarServicios(Event $event, array $nextIds): void
    {
        $actuales = EventoProductoServicio::query()
            ->where('evento_id', $event->id)
            ->where('activo', true)
            ->pluck('producto_servicio_id')
            ->map(fn ($id) => (int) $id)
            ->all();
        $quitados = array_values(array_diff($actuales, $nextIds));
        if ($quitados === [] || ! $this->eventoTieneAbonos($event)) {
            return;
        }

        throw ValidationException::withMessages([
            'producto_servicio_ids' => ['Ya hay un abono en esta actividad. No se pueden quitar servicios.'],
        ]);
    }

    private function assertVisibleEvent(User $actor, Event $event): void
    {
        abort_unless($event->evento_padre_id === null, Response::HTTP_NOT_FOUND);
        abort_unless(
            $event->estado !== Event::ESTADO_CANCELADO && $event->isVisibleTo($actor),
            Response::HTTP_FORBIDDEN,
            'No puedes gestionar los servicios de este evento.',
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function productoPayload(ProductoServicio $producto): array
    {
        return [
            'id' => (int) $producto->id,
            'organizacion_id' => $producto->organizacion_id ? (int) $producto->organizacion_id : null,
            'nombre' => $producto->nombre,
            'tipo' => $producto->tipo,
            'descripcion' => $producto->descripcion,
            'precio' => $producto->precio,
            'unidad' => $producto->unidad,
            'image_url' => $this->publicFiles->url($producto->image_path),
            'icono' => $producto->icono,
            'activo' => (bool) $producto->activo,
        ];
    }

    private function storeImage(ProductoServicio $producto, UploadedFile $file): ProductoServicio
    {
        $stored = $this->images->store(
            $file,
            'servicios/'.((int) $producto->organizacion_id ?: 'club'),
            'icono',
        );
        $this->deleteStoredImage($producto->image_path);
        $producto->update(['image_path' => $stored->path]);

        return $producto->fresh() ?? $producto;
    }

    private function deleteStoredImage(?string $path): void
    {
        if (! is_string($path) || $path === '') {
            return;
        }
        if (Storage::disk('public')->exists($path)) {
            Storage::disk('public')->delete($path);
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function ofertaPayload(EventoProductoServicio $oferta): array
    {
        return [
            'id' => (int) $oferta->id,
            'evento_id' => (int) $oferta->evento_id,
            'producto_servicio_id' => (int) $oferta->producto_servicio_id,
            'precio' => $oferta->precio,
            'activo' => (bool) $oferta->activo,
            'producto' => $oferta->producto ? $this->productoPayload($oferta->producto) : null,
        ];
    }
}
