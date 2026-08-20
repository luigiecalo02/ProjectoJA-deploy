<?php

namespace App\Modules\Organizations\Http\Controllers;

use App\Modules\Organizations\Models\Ciudad;
use App\Modules\Organizations\Models\Departamento;
use App\Modules\Organizations\Models\Organizacion;
use App\Modules\Organizations\Models\Pais;
use App\Modules\Organizations\Services\UbicacionService;
use App\Modules\Shared\Http\Responses\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final class UbicacionController
{
    public function __construct(private readonly UbicacionService $ubicacionService) {}

    public function paises(Request $request): JsonResponse
    {
        abort_unless($request->user()->can('viewAny', Organizacion::class), Response::HTTP_FORBIDDEN);

        $items = collect($this->ubicacionService->paises())->map(fn (Pais $pais) => [
            'id' => $pais->id,
            'codigo' => $pais->codigo,
            'nombre' => $pais->nombre,
            'label' => $this->ubicacionLabel($pais->codigo, $pais->nombre),
        ]);

        return ApiResponse::success($items);
    }

    public function departamentos(Request $request): JsonResponse
    {
        abort_unless($request->user()->can('viewAny', Organizacion::class), Response::HTTP_FORBIDDEN);

        $paisId = $request->integer('pais_id') ?: null;
        $items = collect($this->ubicacionService->departamentos($paisId ?: null))->map(fn (Departamento $dep) => [
            'id' => $dep->id,
            'pais_id' => $dep->pais_id,
            'codigo' => $dep->codigo,
            'nombre' => $dep->nombre,
            'label' => $dep->nombre,
            'pais_nombre' => $dep->pais?->nombre,
        ]);

        return ApiResponse::success($items);
    }

    public function ciudades(Request $request): JsonResponse
    {
        abort_unless($request->user()->can('viewAny', Organizacion::class), Response::HTTP_FORBIDDEN);

        $departamentoId = $request->integer('departamento_id') ?: null;
        $departamentoIds = $request->input('departamento_ids');
        $departamentoIds = is_array($departamentoIds)
            ? array_map('intval', $departamentoIds)
            : (is_string($departamentoIds) ? array_filter(array_map('intval', explode(',', $departamentoIds))) : null);
        $items = collect($this->ubicacionService->ciudades($departamentoId ?: null, $departamentoIds))->map(fn (Ciudad $ciudad) => [
            'id' => $ciudad->id,
            'departamento_id' => $ciudad->departamento_id,
            'codigo' => $ciudad->codigo,
            'nombre' => $ciudad->nombre,
            'label' => $ciudad->nombre,
            'departamento_nombre' => $ciudad->departamento?->nombre,
        ]);

        return ApiResponse::success($items);
    }

    private function ubicacionLabel(?string $codigo, string $nombre): string
    {
        return $codigo ? "{$codigo} — {$nombre}" : $nombre;
    }
}
