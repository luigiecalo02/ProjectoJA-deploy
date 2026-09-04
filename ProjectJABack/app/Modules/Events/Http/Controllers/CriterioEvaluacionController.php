<?php

namespace App\Modules\Events\Http\Controllers;

use App\Modules\Events\Models\CriterioEvaluacion;
use App\Modules\Events\Models\Event;
use App\Modules\Events\Services\CriterioEvaluacionService;
use App\Modules\Shared\Http\Responses\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final class CriterioEvaluacionController
{
    public function __construct(private readonly CriterioEvaluacionService $service) {}

    public function index(Request $request): JsonResponse
    {
        abort_unless($request->user()->can('viewAny', Event::class), Response::HTTP_FORBIDDEN);

        $items = collect($this->service->list($request->boolean('todos')))
            ->map(fn (CriterioEvaluacion $c) => $this->payload($c))
            ->values()
            ->all();

        return ApiResponse::success($items);
    }

    public function store(Request $request): JsonResponse
    {
        abort_unless($request->user()->can('create', Event::class), Response::HTTP_FORBIDDEN);

        $data = $request->validate($this->rules());
        $criterio = $this->service->create($data);

        return ApiResponse::success($this->payload($criterio), 'Criterio creado', Response::HTTP_CREATED);
    }

    public function update(Request $request, CriterioEvaluacion $criterioEvaluacion): JsonResponse
    {
        abort_unless($request->user()->hasPermission('events.update'), Response::HTTP_FORBIDDEN);

        $data = $request->validate($this->rules(false));
        $criterio = $this->service->update($criterioEvaluacion, $data);

        return ApiResponse::success($this->payload($criterio), 'Criterio actualizado');
    }

    public function destroy(Request $request, CriterioEvaluacion $criterioEvaluacion): JsonResponse
    {
        abort_unless($request->user()->hasPermission('events.delete'), Response::HTTP_FORBIDDEN);

        $this->service->delete($criterioEvaluacion);

        return ApiResponse::success(null, 'Criterio eliminado');
    }

    /**
     * @return array<string, list<string>>
     */
    private function rules(bool $creating = true): array
    {
        return [
            'nombre' => [$creating ? 'required' : 'sometimes', 'required', 'string', 'max:180'],
            'descripcion' => ['nullable', 'string'],
            'color' => ['nullable', 'string', 'max:32'],
            'icono' => ['nullable', 'string', 'max:64'],
            'estado' => ['sometimes', 'boolean'],
            'orden' => ['nullable', 'integer', 'min:0', 'max:65535'],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function payload(CriterioEvaluacion $criterio): array
    {
        return [
            'id' => $criterio->id,
            'nombre' => $criterio->nombre,
            'descripcion' => $criterio->descripcion,
            'color' => $criterio->color,
            'icono' => $criterio->icono,
            'estado' => (bool) $criterio->estado,
            'es_sistema' => (bool) $criterio->es_sistema,
            'orden' => (int) $criterio->orden,
        ];
    }
}
