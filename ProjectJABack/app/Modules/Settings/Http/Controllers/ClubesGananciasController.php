<?php

namespace App\Modules\Settings\Http\Controllers;

use App\Modules\Settings\Services\ClubesGananciasService;
use App\Modules\Shared\Http\Responses\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final class ClubesGananciasController
{
    public function __construct(private readonly ClubesGananciasService $ganancias) {}

    public function index(Request $request): JsonResponse
    {
        $this->assertClubesClient($request);

        return ApiResponse::success($this->ganancias->listDistribuciones($request->user()));
    }

    public function store(Request $request): JsonResponse
    {
        $this->assertClubesClient($request);
        $data = $request->validate($this->distribucionRules());

        return ApiResponse::success(
            $this->ganancias->createDistribucion($request->user(), $data),
            'Distribución creada',
            Response::HTTP_CREATED,
        );
    }

    public function update(Request $request, int $distribucion): JsonResponse
    {
        $this->assertClubesClient($request);
        $data = $request->validate($this->distribucionRules(true));

        return ApiResponse::success(
            $this->ganancias->updateDistribucion($request->user(), $distribucion, $data),
            'Distribución actualizada',
        );
    }

    public function destroy(Request $request, int $distribucion): JsonResponse
    {
        $this->assertClubesClient($request);
        $this->ganancias->deleteDistribucion($request->user(), $distribucion);

        return ApiResponse::success(null, 'Distribución eliminada');
    }

    public function indexEclesiasticas(Request $request): JsonResponse
    {
        $this->assertClubesClient($request);

        return ApiResponse::success($this->ganancias->listEclesiasticas($request->user()));
    }

    public function storeEclesiastica(Request $request): JsonResponse
    {
        $this->assertClubesClient($request);
        $data = $request->validate($this->eclesiasticaRules());

        return ApiResponse::success(
            $this->ganancias->createEclesiastica($request->user(), $data),
            'Configuración eclesiástica creada',
            Response::HTTP_CREATED,
        );
    }

    public function updateEclesiastica(Request $request, int $eclesiastica): JsonResponse
    {
        $this->assertClubesClient($request);
        $data = $request->validate($this->eclesiasticaRules(true));

        return ApiResponse::success(
            $this->ganancias->updateEclesiastica($request->user(), $eclesiastica, $data),
            'Configuración eclesiástica actualizada',
        );
    }

    public function destroyEclesiastica(Request $request, int $eclesiastica): JsonResponse
    {
        $this->assertClubesClient($request);
        $this->ganancias->deleteEclesiastica($request->user(), $eclesiastica);

        return ApiResponse::success(null, 'Configuración eclesiástica eliminada');
    }

    /**
     * @return array<string, list<string>>
     */
    private function distribucionRules(bool $partial = false): array
    {
        $required = $partial ? 'sometimes' : 'required';

        return [
            'nombre' => [$required, 'string', 'max:255'],
            'club' => [$required, 'numeric', 'min:0', 'max:100'],
            'miembros' => [$required, 'numeric', 'min:0', 'max:100'],
            'extras' => [$required, 'numeric', 'min:0', 'max:100'],
            'es_predeterminada' => ['sometimes', 'boolean'],
        ];
    }

    /**
     * @return array<string, list<string>>
     */
    private function eclesiasticaRules(bool $partial = false): array
    {
        $required = $partial ? 'sometimes' : 'required';

        return [
            'nombre' => [$required, 'string', 'max:255'],
            'diezmo' => [$required, 'numeric', 'min:0', 'max:100'],
            'ofrenda' => [$required, 'numeric', 'min:0', 'max:100'],
            'distribucion_id' => [$required, 'integer', 'min:1'],
            'es_predeterminada' => ['sometimes', 'boolean'],
        ];
    }

    private function assertClubesClient(Request $request): void
    {
        abort_unless(
            $request->header('X-Clubes-Client') === 'clubes',
            Response::HTTP_FORBIDDEN,
            'La distribución de ganancias solo se gestiona desde el front de Clubes.',
        );
    }
}
