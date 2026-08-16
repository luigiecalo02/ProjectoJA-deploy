<?php

namespace App\Modules\Events\Http\Controllers;

use App\Modules\Events\Models\CategoriaSubevento;
use App\Modules\Events\Models\Event;
use App\Modules\Events\Services\CategoriaSubeventoService;
use App\Modules\Shared\Http\Responses\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final class CategoriaSubeventoController
{
    public function __construct(private readonly CategoriaSubeventoService $service) {}

    public function index(Request $request): JsonResponse
    {
        abort_unless($request->user()->can('viewAny', Event::class), Response::HTTP_FORBIDDEN);

        $incluirInactivas = $request->boolean('todos');
        $items = collect($this->service->list($incluirInactivas))
            ->map(fn (CategoriaSubevento $cat) => $this->payload($cat))
            ->values()
            ->all();

        return ApiResponse::success($items);
    }

    public function store(Request $request): JsonResponse
    {
        abort_unless($request->user()->can('create', Event::class), Response::HTTP_FORBIDDEN);

        $data = $request->validate($this->rules());
        $categoria = $this->service->create($data);

        return ApiResponse::success($this->payload($categoria), 'Categoría creada', Response::HTTP_CREATED);
    }

    public function update(Request $request, CategoriaSubevento $categoriaSubevento): JsonResponse
    {
        abort_unless($request->user()->hasPermission('events.update'), Response::HTTP_FORBIDDEN);

        $data = $request->validate($this->rules(false));
        $categoria = $this->service->update($categoriaSubevento, $data);

        return ApiResponse::success($this->payload($categoria), 'Categoría actualizada');
    }

    public function destroy(Request $request, CategoriaSubevento $categoriaSubevento): JsonResponse
    {
        abort_unless($request->user()->hasPermission('events.delete'), Response::HTTP_FORBIDDEN);

        $this->service->delete($categoriaSubevento);

        return ApiResponse::success(null, 'Categoría eliminada');
    }

    /**
     * @return array<string, list<string>>
     */
    private function rules(bool $creating = true): array
    {
        return [
            'nombre' => [$creating ? 'required' : 'sometimes', 'required', 'string', 'max:120'],
            'slug' => ['nullable', 'string', 'max:120'],
            'color' => ['nullable', 'string', 'max:32'],
            'icono' => ['nullable', 'string', 'max:64'],
            'orden' => ['nullable', 'integer', 'min:0', 'max:65535'],
            'estado' => ['sometimes', 'boolean'],
            'maneja_puntos' => ['sometimes', 'boolean'],
            'maneja_fecha_inicio' => ['sometimes', 'boolean'],
            'maneja_fecha_fin' => ['sometimes', 'boolean'],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function payload(CategoriaSubevento $categoria): array
    {
        return [
            'id' => $categoria->id,
            'nombre' => $categoria->nombre,
            'slug' => $categoria->slug,
            'color' => $categoria->color,
            'icono' => $categoria->icono,
            'orden' => (int) $categoria->orden,
            'estado' => (bool) $categoria->estado,
            'maneja_puntos' => (bool) $categoria->maneja_puntos,
            'maneja_fecha_inicio' => (bool) $categoria->maneja_fecha_inicio,
            'maneja_fecha_fin' => (bool) $categoria->maneja_fecha_fin,
        ];
    }
}
