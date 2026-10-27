<?php

namespace App\Modules\Settings\Services;

use App\Models\User;
use App\Modules\Events\Models\Event;
use App\Modules\Events\Models\EventoParticipacionAbono;
use App\Modules\Events\Models\EventoParticipacionVenta;
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
        private readonly EventoGananciaRegistro $ganancias,
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
                'ganancia_registro' => EventoGananciaRegistro::presentar($event),
            ],
            'catalogo' => $this->productos->listCatalog(true, $orgId)
                ->map(fn (ProductoServicio $producto) => $this->productoPayload($producto))
                ->values()
                ->all(),
            'ofertas' => $this->ofertasDelEvento($actor, $event),
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function ofertasDelEvento(User $actor, Event $event): array
    {
        $directiva = $actor->isClubDirectiva();
        $pedidos = $directiva ? $this->cantidadesSolicitadas($event) : [];

        return $this->productos->listOfertasEvento($event)
            ->filter(fn (EventoProductoServicio $oferta) => (bool) $oferta->activo)
            ->map(fn (EventoProductoServicio $oferta) => $this->ofertaPayload($oferta, $directiva, $pedidos))
            ->values()
            ->all();
    }

    /**
     * @param  list<int>  $productoIds
     * @param  list<array<string, mixed>>  $proyecciones
     * @return array<string, mixed>
     */
    public function syncOfertas(
        User $actor,
        Event $event,
        array $productoIds,
        array $proyecciones = [],
        bool $syncConfiguracion = false,
        ?int $gananciaEclesiasticaId = null,
    ): array {
        $orgId = $this->assertCanWrite($actor);
        $this->assertVisibleEvent($actor, $event);

        $nextIds = array_values(array_unique(array_map('intval', $productoIds)));
        $this->assertPuedeQuitarServicios($event, $nextIds);
        $cantidades = $actor->isClubDirectiva() ? $this->cantidadesProyectadas($proyecciones) : [];
        $inversiones = $actor->isClubDirectiva() ? $this->inversionesProyectadas($proyecciones) : [];
        $puedeCambiarPrecio = $event->estado === Event::ESTADO_PUBLICADO;
        $precios = $puedeCambiarPrecio ? $this->preciosProyectados($proyecciones) : [];
        $existentes = EventoProductoServicio::query()
            ->where('evento_id', $event->id)
            ->get()
            ->keyBy(fn (EventoProductoServicio $oferta) => (int) $oferta->producto_servicio_id);

        $catalog = $this->productos->listCatalog(false, $orgId)->keyBy('id');
        $items = [];
        foreach ($nextIds as $productoId) {
            $producto = $catalog->get($productoId);
            if (! $producto) {
                throw ValidationException::withMessages([
                    'producto_servicio_ids' => ['Hay servicios que no pertenecen a este club.'],
                ]);
            }
            $actual = $existentes->get($productoId);
            $precio = array_key_exists($productoId, $precios)
                ? $precios[$productoId]
                : ($actual ? (float) $actual->precio : (float) $producto->precio);
            $items[] = [
                'producto_servicio_id' => $productoId,
                'precio' => $precio,
                'activo' => true,
                'cantidad_proyectada' => $cantidades[$productoId] ?? null,
                'inversion' => $inversiones[$productoId] ?? null,
            ];
        }

        $this->productos->syncOfertasEvento($event, $items, $orgId, true);
        if ($syncConfiguracion && $this->puedeCambiarConfiguracion($actor)) {
            $event->ganancia_eclesiastica_id = $gananciaEclesiasticaId ?: null;
            $event->save();
        }
        $this->ganancias->registrar($event->fresh() ?? $event);

        return $this->listOfertas($actor, $event->fresh());
    }

    /**
     * @param  list<array<string, mixed>>  $proyecciones
     * @return array<int, int>
     */
    private function cantidadesProyectadas(array $proyecciones): array
    {
        $cantidades = [];
        foreach ($proyecciones as $row) {
            $productoId = (int) ($row['producto_servicio_id'] ?? 0);
            if ($productoId <= 0) {
                continue;
            }
            $cantidades[$productoId] = max(0, (int) ($row['cantidad'] ?? 0));
        }

        return $cantidades;
    }

    /**
     * @param  list<array<string, mixed>>  $proyecciones
     * @return array<int, float>
     */
    /**
     * @param  list<array<string, mixed>>  $proyecciones
     * @return array<int, float>
     */
    private function preciosProyectados(array $proyecciones): array
    {
        $precios = [];
        foreach ($proyecciones as $row) {
            if (! array_key_exists('precio', $row) || $row['precio'] === null || $row['precio'] === '') {
                continue;
            }
            $productoId = (int) ($row['producto_servicio_id'] ?? 0);
            if ($productoId <= 0) {
                continue;
            }
            $precios[$productoId] = round(max(0, (float) $row['precio']), 2);
        }

        return $precios;
    }

    /**
     * @param  list<array<string, mixed>>  $proyecciones
     * @return array<int, float>
     */
    private function inversionesProyectadas(array $proyecciones): array
    {
        $inversiones = [];
        foreach ($proyecciones as $row) {
            if (! array_key_exists('inversion', $row) || $row['inversion'] === null || $row['inversion'] === '') {
                continue;
            }
            $productoId = (int) ($row['producto_servicio_id'] ?? 0);
            if ($productoId <= 0) {
                continue;
            }
            $inversiones[$productoId] = round(max(0, (float) $row['inversion']), 2);
        }

        return $inversiones;
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

    private function puedeCambiarConfiguracion(User $actor): bool
    {
        return $actor->isSuperAdmin() || in_array('director', $actor->roleNames(), true);
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
     * @param  array<int, int>  $pedidos
     * @return array<string, mixed>
     */
    private function ofertaPayload(EventoProductoServicio $oferta, bool $includeProyeccion = false, array $pedidos = []): array
    {
        $productoId = (int) $oferta->producto_servicio_id;
        $qty = $includeProyeccion
            ? (int) ($pedidos[$productoId] ?? 0)
            : (int) ($oferta->cantidad_proyectada ?? 0);

        return [
            'id' => (int) $oferta->id,
            'evento_id' => (int) $oferta->evento_id,
            'producto_servicio_id' => $productoId,
            'precio' => $oferta->precio,
            'cantidad_proyectada' => $includeProyeccion ? $qty : null,
            'inversion' => round((float) ($oferta->inversion ?? 0), 2),
            'total' => round($includeProyeccion ? $qty * (float) $oferta->precio : (float) ($oferta->total ?? 0), 2),
            'ganancia_unidad' => round((float) ($oferta->ganancia_unidad ?? 0), 2),
            'activo' => (bool) $oferta->activo,
            'producto' => $oferta->producto ? $this->productoPayload($oferta->producto) : null,
        ];
    }

    /**
     * @return array<int, int>
     */
    private function cantidadesSolicitadas(Event $event): array
    {
        return EventoParticipacionVenta::totalesPorProducto(
            (int) $event->id,
            $event->cuentaUnidadesEntregadas(),
        );
    }
}
