<?php

namespace App\Modules\Lugares\Http\Controllers;

use App\Modules\Lugares\Models\Lugar;
use App\Modules\Lugares\Services\LugarService;
use App\Modules\Shared\Http\Responses\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\Response;

final class LugarController
{
    public function __construct(private readonly LugarService $lugares) {}

    public function index(Request $request): JsonResponse
    {
        abort_unless($request->user()?->can('viewAny', Lugar::class), Response::HTTP_FORBIDDEN);

        $paginator = $this->lugares->list(
            $request->only(['q', 'estado']),
            (int) $request->integer('per_page', 15),
        );
        $paginator->getCollection()->transform(fn (Lugar $lugar) => $this->lugares->toPayload($lugar));

        return ApiResponse::fromPaginator($paginator);
    }

    public function catalogos(Request $request): JsonResponse
    {
        abort_unless($request->user()?->can('viewAny', Lugar::class), Response::HTTP_FORBIDDEN);

        return ApiResponse::success($this->lugares->catalogos());
    }

    public function store(Request $request): JsonResponse
    {
        abort_unless($request->user()?->can('create', Lugar::class), Response::HTTP_FORBIDDEN);

        $lugar = $this->lugares->create($this->validated($request));

        return ApiResponse::success($this->lugares->toPayload($lugar), 'Lugar creado', Response::HTTP_CREATED);
    }

    public function show(Request $request, Lugar $lugar): JsonResponse
    {
        abort_unless($request->user()?->can('view', $lugar), Response::HTTP_FORBIDDEN);

        return ApiResponse::success($this->lugares->toPayload($this->lugares->find($lugar->id)));
    }

    public function update(Request $request, Lugar $lugar): JsonResponse
    {
        abort_unless($request->user()?->can('update', $lugar), Response::HTTP_FORBIDDEN);

        $updated = $this->lugares->update($lugar, $this->validated($request, true));

        return ApiResponse::success($this->lugares->toPayload($updated), 'Lugar actualizado');
    }

    public function destroy(Request $request, Lugar $lugar): JsonResponse
    {
        abort_unless($request->user()?->can('delete', $lugar), Response::HTTP_FORBIDDEN);
        $this->lugares->delete($lugar);

        return ApiResponse::success(null, 'Lugar eliminado');
    }

    /** @return array<string, mixed> */
    private function validated(Request $request, bool $partial = false): array
    {
        $nombre = $partial ? ['sometimes', 'required', 'string', 'max:255'] : ['required', 'string', 'max:255'];

        return $request->validate([
            'nombre' => $nombre,
            'descripcion' => ['nullable', 'string'],
            'latitud' => ['nullable', 'numeric', 'between:-90,90'],
            'longitud' => ['nullable', 'numeric', 'between:-180,180'],
            'nivel_zoom' => ['nullable', 'integer', 'between:1,22'],
            'estado' => ['nullable', 'string', Rule::in([Lugar::ESTADO_ACTIVO, Lugar::ESTADO_INACTIVO])],
            'terreno_ids' => ['sometimes', 'array'],
            'terreno_ids.*' => ['integer', 'exists:terrenos,id'],
            'cabana_ids' => ['sometimes', 'array'],
            'cabana_ids.*' => ['integer', 'exists:cabanas,id'],
        ]);
    }
}
