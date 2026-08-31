<?php

namespace App\Modules\Terrains\Http\Controllers;

use App\Modules\Shared\Http\Responses\ApiResponse;
use App\Modules\Terrains\Http\Requests\StoreTerrenoRequest;
use App\Modules\Terrains\Http\Requests\UpdateTerrenoRequest;
use App\Modules\Terrains\Models\ConfiguracionTerreno;
use App\Modules\Terrains\Models\EstructuraTerreno;
use App\Modules\Terrains\Models\LoteTerreno;
use App\Modules\Terrains\Models\Terreno;
use App\Modules\Terrains\Models\ZonaTerreno;
use App\Modules\Terrains\Services\ConfiguracionTerrenoService;
use App\Modules\Terrains\Services\EstructuraTerrenoService;
use App\Modules\Terrains\Services\LoteTerrenoService;
use App\Modules\Terrains\Services\TerrenoService;
use App\Modules\Terrains\Services\ZonaTerrenoService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\Response;

final class TerrenoController
{
    public function __construct(
        private readonly TerrenoService $terrenoService,
        private readonly ConfiguracionTerrenoService $configService,
        private readonly ZonaTerrenoService $zonaService,
        private readonly LoteTerrenoService $loteService,
        private readonly EstructuraTerrenoService $estructuraService,
    ) {}

    public function index(Request $request): JsonResponse
    {
        abort_unless($request->user()->can('viewAny', Terreno::class), Response::HTTP_FORBIDDEN);

        $paginator = $this->terrenoService->list(
            $request->only(['q', 'estado', 'lugar_id']),
            (int) $request->integer('per_page', 15),
        );
        $paginator->getCollection()->transform(fn (Terreno $t) => $this->payloadTerreno($t));

        return ApiResponse::fromPaginator($paginator);
    }

    public function store(StoreTerrenoRequest $request): JsonResponse
    {
        $terreno = $this->terrenoService->create($request->user(), $request->validated());

        return ApiResponse::success($this->payloadTerreno($terreno, true), 'Terreno creado', Response::HTTP_CREATED);
    }

    public function show(Request $request, Terreno $terreno): JsonResponse
    {
        abort_unless($request->user()->can('view', $terreno), Response::HTTP_FORBIDDEN);

        return ApiResponse::success($this->payloadTerreno($this->terrenoService->find($terreno->id), true));
    }

    public function update(UpdateTerrenoRequest $request, Terreno $terreno): JsonResponse
    {
        $terreno = $this->terrenoService->update($terreno, $request->validated());

        return ApiResponse::success($this->payloadTerreno($terreno, true), 'Terreno actualizado');
    }

    public function destroy(Request $request, Terreno $terreno): JsonResponse
    {
        abort_unless($request->user()->can('delete', $terreno), Response::HTTP_FORBIDDEN);
        $this->terrenoService->delete($terreno);

        return ApiResponse::success(null, 'Terreno eliminado');
    }

    public function imagen(Request $request, Terreno $terreno): JsonResponse
    {
        abort_unless($request->user()->can('update', $terreno), Response::HTTP_FORBIDDEN);
        $request->validate([
            'imagen' => ['required', 'image', 'mimes:jpg,jpeg,png,webp,gif', 'max:8192'],
        ]);

        $terreno = $this->terrenoService->storeImagen($terreno, $request->file('imagen'), $request->user());

        return ApiResponse::success($this->payloadTerreno($terreno, true), 'Imagen actualizada');
    }

    public function configsIndex(Request $request, Terreno $terreno): JsonResponse
    {
        abort_unless($request->user()->can('view', $terreno), Response::HTTP_FORBIDDEN);
        $items = $this->configService->listByTerreno($terreno)->map(fn (ConfiguracionTerreno $c) => $this->payloadConfig($c));

        return ApiResponse::success($items);
    }

    public function configsStore(Request $request, Terreno $terreno): JsonResponse
    {
        abort_unless($request->user()->can('update', $terreno), Response::HTTP_FORBIDDEN);
        $data = $request->validate([
            'nombre' => ['required', 'string', 'max:255'],
            'descripcion' => ['nullable', 'string'],
            'es_default' => ['nullable', 'boolean'],
            'orden' => ['nullable', 'integer', 'min:0'],
            'estado' => ['nullable', 'string', 'max:30'],
        ]);

        $config = $this->configService->create($terreno, $data);

        return ApiResponse::success($this->payloadConfig($config, true), 'Configuración creada', Response::HTTP_CREATED);
    }

    public function configsShow(Request $request, ConfiguracionTerreno $configuracion): JsonResponse
    {
        abort_unless($request->user()->can('view', $configuracion->terreno), Response::HTTP_FORBIDDEN);

        return ApiResponse::success($this->payloadConfig($this->configService->find($configuracion->id), true));
    }

    public function configsUpdate(Request $request, ConfiguracionTerreno $configuracion): JsonResponse
    {
        abort_unless($request->user()->can('update', $configuracion->terreno), Response::HTTP_FORBIDDEN);
        $data = $request->validate([
            'nombre' => ['sometimes', 'string', 'max:255'],
            'descripcion' => ['nullable', 'string'],
            'es_default' => ['nullable', 'boolean'],
            'orden' => ['nullable', 'integer', 'min:0'],
            'estado' => ['nullable', 'string', 'max:30'],
        ]);

        $config = $this->configService->update($configuracion, $data);

        return ApiResponse::success($this->payloadConfig($config, true), 'Configuración actualizada');
    }

    public function configsDestroy(Request $request, ConfiguracionTerreno $configuracion): JsonResponse
    {
        abort_unless($request->user()->can('update', $configuracion->terreno), Response::HTTP_FORBIDDEN);
        $this->configService->delete($configuracion);

        return ApiResponse::success(null, 'Configuración eliminada');
    }

    public function configsDuplicate(Request $request, ConfiguracionTerreno $configuracion): JsonResponse
    {
        abort_unless($request->user()->can('update', $configuracion->terreno), Response::HTTP_FORBIDDEN);
        $data = $request->validate([
            'nombre' => ['nullable', 'string', 'max:255'],
        ]);

        $copy = $this->configService->duplicate($configuracion, $data['nombre'] ?? null);

        return ApiResponse::success($this->payloadConfig($copy, true), 'Configuración duplicada', Response::HTTP_CREATED);
    }

    public function estructurasIndex(Request $request, Terreno $terreno): JsonResponse
    {
        abort_unless($request->user()->can('view', $terreno), Response::HTTP_FORBIDDEN);
        $items = $this->estructuraService->listByTerreno($terreno)
            ->map(fn (EstructuraTerreno $e) => $this->payloadEstructura($e));

        return ApiResponse::success($items);
    }

    public function estructurasStore(Request $request, Terreno $terreno): JsonResponse
    {
        abort_unless($request->user()->can('update', $terreno), Response::HTTP_FORBIDDEN);
        $data = $request->validate([
            'nombre' => ['required', 'string', 'max:255'],
            'tipo' => ['nullable', 'string', Rule::in(EstructuraTerreno::TIPOS)],
            'descripcion' => ['nullable', 'string'],
            'geometria' => ['nullable', 'array'],
            'area' => ['nullable', 'numeric', 'min:0'],
            'perimetro' => ['nullable', 'numeric', 'min:0'],
            'color' => ['nullable', 'string', 'max:20'],
            'orden' => ['nullable', 'integer', 'min:0'],
            'estado' => ['nullable', 'string', 'max:30'],
        ]);

        $estructura = $this->estructuraService->create($terreno, $data);

        return ApiResponse::success($this->payloadEstructura($estructura), 'Estructura creada', Response::HTTP_CREATED);
    }

    public function estructurasUpdate(Request $request, EstructuraTerreno $estructura): JsonResponse
    {
        abort_unless($request->user()->can('update', $estructura->terreno), Response::HTTP_FORBIDDEN);
        $data = $request->validate([
            'nombre' => ['sometimes', 'string', 'max:255'],
            'tipo' => ['nullable', 'string', Rule::in(EstructuraTerreno::TIPOS)],
            'descripcion' => ['nullable', 'string'],
            'geometria' => ['nullable', 'array'],
            'area' => ['nullable', 'numeric', 'min:0'],
            'perimetro' => ['nullable', 'numeric', 'min:0'],
            'color' => ['nullable', 'string', 'max:20'],
            'orden' => ['nullable', 'integer', 'min:0'],
            'estado' => ['nullable', 'string', 'max:30'],
        ]);

        $estructura = $this->estructuraService->update($estructura, $data);

        return ApiResponse::success($this->payloadEstructura($estructura), 'Estructura actualizada');
    }

    public function estructurasDestroy(Request $request, EstructuraTerreno $estructura): JsonResponse
    {
        abort_unless($request->user()->can('update', $estructura->terreno), Response::HTTP_FORBIDDEN);
        $this->estructuraService->delete($estructura);

        return ApiResponse::success(null, 'Estructura eliminada');
    }

    public function zonasStore(Request $request, ConfiguracionTerreno $configuracion): JsonResponse
    {
        abort_unless($request->user()->can('update', $configuracion->terreno), Response::HTTP_FORBIDDEN);
        $data = $request->validate([
            'nombre' => ['required', 'string', 'max:255'],
            'descripcion' => ['nullable', 'string'],
            'geometria' => ['nullable', 'array'],
            'area' => ['nullable', 'numeric', 'min:0'],
            'perimetro' => ['nullable', 'numeric', 'min:0'],
            'color' => ['nullable', 'string', 'max:20'],
            'orden' => ['nullable', 'integer', 'min:0'],
            'estado' => ['nullable', 'string', 'max:30'],
        ]);

        $zona = $this->zonaService->create($configuracion, $data);

        return ApiResponse::success($this->payloadZona($zona, true), 'Zona creada', Response::HTTP_CREATED);
    }

    public function zonasUpdate(Request $request, ZonaTerreno $zona): JsonResponse
    {
        $zona->loadMissing('configuracion.terreno');
        abort_unless($request->user()->can('update', $zona->configuracion->terreno), Response::HTTP_FORBIDDEN);
        $data = $request->validate([
            'nombre' => ['sometimes', 'string', 'max:255'],
            'descripcion' => ['nullable', 'string'],
            'geometria' => ['nullable', 'array'],
            'area' => ['nullable', 'numeric', 'min:0'],
            'perimetro' => ['nullable', 'numeric', 'min:0'],
            'color' => ['nullable', 'string', 'max:20'],
            'orden' => ['nullable', 'integer', 'min:0'],
            'estado' => ['nullable', 'string', 'max:30'],
        ]);

        $zona = $this->zonaService->update($zona, $data);

        return ApiResponse::success($this->payloadZona($zona, true), 'Zona actualizada');
    }

    public function zonasDestroy(Request $request, ZonaTerreno $zona): JsonResponse
    {
        $zona->loadMissing('configuracion.terreno');
        abort_unless($request->user()->can('update', $zona->configuracion->terreno), Response::HTTP_FORBIDDEN);
        $this->zonaService->delete($zona);

        return ApiResponse::success(null, 'Zona eliminada');
    }

    public function lotesStoreOnZona(Request $request, ZonaTerreno $zona): JsonResponse
    {
        $zona->loadMissing('configuracion.terreno');
        abort_unless($request->user()->can('update', $zona->configuracion->terreno), Response::HTTP_FORBIDDEN);
        $data = $request->validate([
            'codigo' => ['required', 'string', 'max:50'],
            'nombre' => ['nullable', 'string', 'max:255'],
            'descripcion' => ['nullable', 'string'],
            'geometria' => ['nullable', 'array'],
            'area' => ['nullable', 'numeric', 'min:0'],
            'perimetro' => ['nullable', 'numeric', 'min:0'],
            'capacidad_maxima' => ['nullable', 'integer', 'min:0'],
            'tipo_capacidad' => ['nullable', 'in:calculada,manual'],
            'orden' => ['nullable', 'integer', 'min:0'],
            'estado' => ['nullable', 'string', 'max:30'],
        ]);

        $lote = $this->loteService->create($zona, $data);

        return ApiResponse::success($this->payloadLote($lote), 'Lote creado', Response::HTTP_CREATED);
    }

    public function lotesStoreOnConfig(Request $request, ConfiguracionTerreno $configuracion): JsonResponse
    {
        abort_unless($request->user()->can('update', $configuracion->terreno), Response::HTTP_FORBIDDEN);
        $data = $request->validate([
            'codigo' => ['required', 'string', 'max:50'],
            'nombre' => ['nullable', 'string', 'max:255'],
            'descripcion' => ['nullable', 'string'],
            'geometria' => ['nullable', 'array'],
            'area' => ['nullable', 'numeric', 'min:0'],
            'perimetro' => ['nullable', 'numeric', 'min:0'],
            'capacidad_maxima' => ['nullable', 'integer', 'min:0'],
            'tipo_capacidad' => ['nullable', 'in:calculada,manual'],
            'orden' => ['nullable', 'integer', 'min:0'],
            'estado' => ['nullable', 'string', 'max:30'],
            'zona_terreno_id' => ['nullable', 'integer', 'exists:zonas_terreno,id'],
        ]);

        $zona = null;
        if (! empty($data['zona_terreno_id'])) {
            $zona = ZonaTerreno::query()->findOrFail((int) $data['zona_terreno_id']);
        }

        $lote = $this->loteService->createOnConfig($configuracion, $data, $zona);

        return ApiResponse::success($this->payloadLote($lote), 'Lote creado', Response::HTTP_CREATED);
    }

    public function lotesUpdate(Request $request, LoteTerreno $lote): JsonResponse
    {
        $lote->loadMissing('configuracion.terreno');
        abort_unless($request->user()->can('update', $lote->configuracion->terreno), Response::HTTP_FORBIDDEN);
        $data = $request->validate([
            'codigo' => ['sometimes', 'string', 'max:50'],
            'nombre' => ['nullable', 'string', 'max:255'],
            'descripcion' => ['nullable', 'string'],
            'geometria' => ['nullable', 'array'],
            'area' => ['nullable', 'numeric', 'min:0'],
            'perimetro' => ['nullable', 'numeric', 'min:0'],
            'capacidad_maxima' => ['nullable', 'integer', 'min:0'],
            'tipo_capacidad' => ['nullable', 'in:calculada,manual'],
            'orden' => ['nullable', 'integer', 'min:0'],
            'estado' => ['nullable', 'string', 'max:30'],
        ]);

        $lote = $this->loteService->update($lote, $data);

        return ApiResponse::success($this->payloadLote($lote), 'Lote actualizado');
    }

    public function lotesDestroy(Request $request, LoteTerreno $lote): JsonResponse
    {
        $lote->loadMissing('configuracion.terreno');
        abort_unless($request->user()->can('update', $lote->configuracion->terreno), Response::HTTP_FORBIDDEN);
        $this->loteService->delete($lote);

        return ApiResponse::success(null, 'Lote eliminado');
    }

    private function payloadTerreno(Terreno $terreno, bool $detailed = false): array
    {
        $data = [
            'id' => $terreno->id,
            'lugar_id' => $terreno->lugar_id,
            'lugar' => $terreno->lugar ? [
                'id' => $terreno->lugar->id,
                'nombre' => $terreno->lugar->nombre,
            ] : null,
            'nombre' => $terreno->nombre,
            'descripcion' => $terreno->descripcion,
            'latitud' => $terreno->latitud,
            'longitud' => $terreno->longitud,
            'nivel_zoom' => $terreno->nivel_zoom,
            'geometria' => $terreno->geometria,
            'area_total' => $terreno->area_total,
            'perimetro' => $terreno->perimetro,
            'metros_por_persona' => $terreno->metros_por_persona,
            'imagen_referencia' => $terreno->imagen_referencia,
            'estado' => $terreno->estado,
            'created_by' => $terreno->created_by,
            'configuraciones_count' => $terreno->configuraciones_count ?? $terreno->configuraciones?->count(),
            'estructuras_count' => $terreno->estructuras_count ?? $terreno->estructuras?->count(),
            'eventos_count' => $terreno->eventos_terrenos_count ?? null,
            'created_at' => $terreno->created_at?->toIso8601String(),
            'updated_at' => $terreno->updated_at?->toIso8601String(),
        ];

        if ($detailed) {
            $data['estructuras'] = ($terreno->estructuras ?? collect())->map(fn (EstructuraTerreno $e) => $this->payloadEstructura($e))->values();
            $data['configuraciones'] = ($terreno->configuraciones ?? collect())->map(fn (ConfiguracionTerreno $c) => $this->payloadConfig($c))->values();
            $data['creador'] = $terreno->creador ? [
                'id' => $terreno->creador->id,
                'name' => $terreno->creador->name,
                'email' => $terreno->creador->email,
            ] : null;
        }

        return $data;
    }

    private function payloadConfig(ConfiguracionTerreno $config, bool $detailed = false): array
    {
        $data = [
            'id' => $config->id,
            'terreno_id' => $config->terreno_id,
            'nombre' => $config->nombre,
            'descripcion' => $config->descripcion,
            'es_default' => (bool) $config->es_default,
            'orden' => $config->orden,
            'estado' => $config->estado,
            'zonas_count' => $config->zonas_count ?? $config->zonas?->count(),
            'lotes_count' => $config->lotes_count ?? $config->lotes?->count(),
        ];

        if ($detailed) {
            $data['terreno'] = $config->terreno ? [
                'id' => $config->terreno->id,
                'nombre' => $config->terreno->nombre,
                'geometria' => $config->terreno->geometria,
                'latitud' => $config->terreno->latitud,
                'longitud' => $config->terreno->longitud,
                'nivel_zoom' => $config->terreno->nivel_zoom,
                'metros_por_persona' => $config->terreno->metros_por_persona,
                'imagen_referencia' => $config->terreno->imagen_referencia,
            ] : null;
            $data['estructuras'] = ($config->terreno?->estructuras ?? collect())
                ->map(fn (EstructuraTerreno $e) => $this->payloadEstructura($e))->values();
            $data['zonas'] = ($config->zonas ?? collect())->map(fn (ZonaTerreno $z) => $this->payloadZona($z, true))->values();
            $data['lotes'] = ($config->lotesSinZona ?? collect())->map(fn (LoteTerreno $l) => $this->payloadLote($l))->values();
        }

        return $data;
    }

    private function payloadEstructura(EstructuraTerreno $estructura): array
    {
        return [
            'id' => $estructura->id,
            'terreno_id' => $estructura->terreno_id,
            'nombre' => $estructura->nombre,
            'tipo' => $estructura->tipo,
            'descripcion' => $estructura->descripcion,
            'geometria' => $estructura->geometria,
            'area' => $estructura->area,
            'perimetro' => $estructura->perimetro,
            'color' => $estructura->color,
            'orden' => $estructura->orden,
            'estado' => $estructura->estado,
        ];
    }

    private function payloadZona(ZonaTerreno $zona, bool $withLotes = false): array
    {
        $data = [
            'id' => $zona->id,
            'configuracion_terreno_id' => $zona->configuracion_terreno_id,
            'nombre' => $zona->nombre,
            'descripcion' => $zona->descripcion,
            'geometria' => $zona->geometria,
            'area' => $zona->area,
            'perimetro' => $zona->perimetro,
            'color' => $zona->color,
            'orden' => $zona->orden,
            'estado' => $zona->estado,
        ];

        if ($withLotes) {
            $data['lotes'] = ($zona->lotes ?? collect())->map(fn (LoteTerreno $l) => $this->payloadLote($l))->values();
        }

        return $data;
    }

    private function payloadLote(LoteTerreno $lote): array
    {
        return [
            'id' => $lote->id,
            'configuracion_terreno_id' => $lote->configuracion_terreno_id,
            'zona_terreno_id' => $lote->zona_terreno_id,
            'codigo' => $lote->codigo,
            'nombre' => $lote->nombre,
            'descripcion' => $lote->descripcion,
            'geometria' => $lote->geometria,
            'area' => $lote->area,
            'perimetro' => $lote->perimetro,
            'capacidad_calculada' => $lote->capacidad_calculada,
            'capacidad_maxima' => $lote->capacidad_maxima,
            'tipo_capacidad' => $lote->tipo_capacidad,
            'orden' => $lote->orden,
            'estado' => $lote->estado,
        ];
    }
}
