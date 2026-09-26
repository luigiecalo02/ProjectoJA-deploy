<?php

namespace App\Modules\Settings\Http\Controllers;

use App\Modules\Events\Models\Event;
use App\Modules\Events\Models\ProductoServicio;
use App\Modules\Settings\Services\ClubesServiciosService;
use App\Modules\Shared\Http\Responses\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final class ClubesServiciosController
{
    public function __construct(private readonly ClubesServiciosService $servicios) {}

    public function index(Request $request): JsonResponse
    {
        $this->assertClubesClient($request);

        return ApiResponse::success(
            $this->servicios->list($request->user(), $request->boolean('activos'))
        );
    }

    public function iconos(Request $request): JsonResponse
    {
        $this->assertClubesClient($request);

        return ApiResponse::success($this->servicios->listIconos($request->user()));
    }

    public function store(Request $request): JsonResponse
    {
        $this->assertClubesClient($request);
        $data = $request->validate([
            'nombre' => ['required', 'string', 'max:255'],
            'descripcion' => ['nullable', 'string'],
            'precio' => ['required', 'numeric', 'min:0'],
            'activo' => ['sometimes', 'boolean'],
            'image' => ['nullable', 'image', 'mimes:jpg,jpeg,png,webp', 'max:5120'],
            'icono' => ['nullable', 'string', 'max:255'],
        ]);
        $image = $request->file('image');
        unset($data['image']);

        return ApiResponse::success(
            $this->servicios->create($request->user(), $data, $image),
            'Servicio creado',
            Response::HTTP_CREATED,
        );
    }

    public function update(Request $request, ProductoServicio $servicio): JsonResponse
    {
        $this->assertClubesClient($request);
        $data = $request->validate([
            'nombre' => ['sometimes', 'string', 'max:255'],
            'descripcion' => ['nullable', 'string'],
            'precio' => ['sometimes', 'numeric', 'min:0'],
            'activo' => ['sometimes', 'boolean'],
            'image' => ['nullable', 'image', 'mimes:jpg,jpeg,png,webp', 'max:5120'],
            'remove_image' => ['sometimes', 'boolean'],
            'icono' => ['nullable', 'string', 'max:255'],
        ]);
        $image = $request->file('image');
        $removeImage = $request->boolean('remove_image');
        unset($data['image'], $data['remove_image']);

        return ApiResponse::success(
            $this->servicios->update($request->user(), $servicio, $data, $image, $removeImage),
            'Servicio actualizado',
        );
    }

    public function destroy(Request $request, ProductoServicio $servicio): JsonResponse
    {
        $this->assertClubesClient($request);
        $this->servicios->delete($request->user(), $servicio);

        return ApiResponse::success(null, 'Servicio eliminado');
    }

    public function ofertas(Request $request, Event $event): JsonResponse
    {
        $this->assertClubesClient($request);

        return ApiResponse::success($this->servicios->listOfertas($request->user(), $event));
    }

    public function syncOfertas(Request $request, Event $event): JsonResponse
    {
        $this->assertClubesClient($request);
        $data = $request->validate([
            'producto_servicio_ids' => ['present', 'array'],
            'producto_servicio_ids.*' => ['integer'],
        ]);

        return ApiResponse::success(
            $this->servicios->syncOfertas($request->user(), $event, $data['producto_servicio_ids'] ?? []),
            'Servicios del evento actualizados',
        );
    }

    private function assertClubesClient(Request $request): void
    {
        abort_unless(
            $request->header('X-Clubes-Client') === 'clubes',
            403,
            'Los servicios del club solo se gestionan desde el front de Clubes.',
        );
    }
}
