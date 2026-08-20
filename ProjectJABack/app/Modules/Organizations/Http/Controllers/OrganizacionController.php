<?php

namespace App\Modules\Organizations\Http\Controllers;

use App\Modules\Organizations\Http\Requests\StoreOrganizacionRequest;
use App\Modules\Organizations\Http\Requests\UpdateOrganizacionRequest;
use App\Modules\Organizations\Models\Organizacion;
use App\Modules\Organizations\Models\TipoOrganizacion;
use App\Modules\Organizations\Services\OrganizacionAprobacionService;
use App\Modules\Organizations\Services\OrganizacionService;
use App\Modules\Shared\Http\Responses\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final class OrganizacionController
{
    public function __construct(
        private readonly OrganizacionService $organizacionService,
        private readonly OrganizacionAprobacionService $aprobacion,
    ) {}

    public function index(Request $request): JsonResponse
    {
        abort_unless($request->user()->can('viewAny', Organizacion::class), Response::HTTP_FORBIDDEN);

        $paginator = $this->organizacionService->list(
            $request->only(['q', 'estado', 'tipo_organizacion_id', 'organizacion_padre_id', 'estado_aprobacion']),
            (int) $request->integer('per_page', 15),
            $request->user(),
        );
        $paginator->getCollection()->transform(fn (Organizacion $org) => $this->payload($org));

        return ApiResponse::fromPaginator($paginator);
    }

    public function tipos(Request $request): JsonResponse
    {
        abort_unless($request->user()->can('viewAny', Organizacion::class), Response::HTTP_FORBIDDEN);

        $tipos = collect($this->organizacionService->tipos())->map(fn (TipoOrganizacion $tipo) => [
            'id' => $tipo->id,
            'tipo_organizacion_padre_id' => $tipo->tipo_organizacion_padre_id,
            'nombre' => $tipo->nombre,
            'descripcion' => $tipo->descripcion,
            'estado' => $tipo->estado,
        ]);

        return ApiResponse::success($tipos);
    }

    public function parentOptions(Request $request): JsonResponse
    {
        abort_unless($request->user()->can('viewAny', Organizacion::class), Response::HTTP_FORBIDDEN);

        $excludeId = $request->integer('exclude_id') ?: null;
        $tipoHijoId = $request->integer('tipo_organizacion_id') ?: null;
        $options = collect($this->organizacionService->parentOptions(
            $excludeId ?: null,
            $tipoHijoId ?: null,
        ))
            ->map(fn (Organizacion $org) => [
                'id' => $org->id,
                'nombre' => $org->nombre,
                'codigo' => $org->codigo,
                'tipo_organizacion_id' => $org->tipo_organizacion_id,
                'tipo_nombre' => $org->tipo?->nombre,
                'organizacion_padre_id' => $org->organizacion_padre_id,
                'pais_id' => $org->pais_id,
                'departamento_id' => $org->departamento_id,
                'ciudad_id' => $org->ciudad_id,
                'pais_nombre' => $org->pais?->nombre,
                'departamento_nombre' => $org->departamento?->nombre,
                'ciudad_nombre' => $org->ciudad?->nombre,
                'departamentos' => $org->relationLoaded('departamentos')
                    ? $org->departamentos->map(fn ($dep) => [
                        'id' => $dep->id,
                        'codigo' => $dep->codigo,
                        'nombre' => $dep->nombre,
                        'label' => $dep->nombre,
                        'pais_id' => $dep->pais_id,
                    ])->values()->all()
                    : [],
                'ciudades' => $org->relationLoaded('ciudades')
                    ? $org->ciudades->map(fn ($ciudad) => [
                        'id' => $ciudad->id,
                        'codigo' => $ciudad->codigo,
                        'nombre' => $ciudad->nombre,
                        'label' => $ciudad->nombre,
                        'departamento_id' => $ciudad->departamento_id,
                    ])->values()->all()
                    : [],
            ]);

        return ApiResponse::success($options);
    }

    public function tree(Request $request): JsonResponse
    {
        abort_unless($request->user()->can('viewAny', Organizacion::class), Response::HTTP_FORBIDDEN);

        $excludeId = $request->integer('exclude_id') ?: null;

        return ApiResponse::success($this->organizacionService->tree(
            $excludeId ?: null,
            $request->only(['q', 'estado', 'tipo_organizacion_id', 'estado_aprobacion']),
            $request->user(),
        ));
    }

    public function store(StoreOrganizacionRequest $request): JsonResponse
    {
        $org = $this->organizacionService->create($request->validated());

        return ApiResponse::success($this->payload($org, true), 'Organización creada', Response::HTTP_CREATED);
    }

    public function show(Request $request, Organizacion $organizacion): JsonResponse
    {
        abort_unless($request->user()->can('view', $organizacion), Response::HTTP_FORBIDDEN);

        return ApiResponse::success($this->payload($this->organizacionService->find($organizacion->id), true));
    }

    public function update(UpdateOrganizacionRequest $request, Organizacion $organizacion): JsonResponse
    {
        $org = $this->organizacionService->update($organizacion, $request->validated());

        return ApiResponse::success($this->payload($org, true), 'Organización actualizada');
    }

    public function destroy(Request $request, Organizacion $organizacion): JsonResponse
    {
        abort_unless($request->user()->can('delete', $organizacion), Response::HTTP_FORBIDDEN);
        $this->organizacionService->delete($organizacion);

        return ApiResponse::success(null, 'Organización eliminada');
    }

    public function approvedOptions(Request $request): JsonResponse
    {
        abort_unless($request->user()->can('viewAny', Organizacion::class), Response::HTTP_FORBIDDEN);

        $tipo = (int) $request->integer('tipo_organizacion_id');
        $padreId = $request->filled('organizacion_padre_id')
            ? (int) $request->integer('organizacion_padre_id')
            : null;

        return ApiResponse::success($this->aprobacion->approvedOfTipo($tipo, $padreId));
    }

    public function approvedClubs(Request $request, Organizacion $organizacion): JsonResponse
    {
        abort_unless($request->user()->can('view', $organizacion), Response::HTTP_FORBIDDEN);

        return ApiResponse::success($this->aprobacion->approvedClubsForIglesia((int) $organizacion->id));
    }

    public function aprobar(Request $request, Organizacion $organizacion): JsonResponse
    {
        abort_unless($request->user()->can('update', $organizacion), Response::HTTP_FORBIDDEN);

        $org = $this->aprobacion->approve(
            $organizacion,
            $request->user(),
            $request->input('observacion'),
        );

        return ApiResponse::success($this->payload($org, true), 'Organización aprobada');
    }

    public function rechazar(Request $request, Organizacion $organizacion): JsonResponse
    {
        abort_unless($request->user()->can('update', $organizacion), Response::HTTP_FORBIDDEN);

        $org = $this->aprobacion->reject(
            $organizacion,
            $request->user(),
            $request->input('observacion'),
        );

        return ApiResponse::success($this->payload($org, true), 'Solicitud rechazada');
    }

    public function reubicar(Request $request, Organizacion $organizacion): JsonResponse
    {
        abort_unless($request->user()->can('update', $organizacion), Response::HTTP_FORBIDDEN);

        $data = $request->validate([
            'asociacion_id' => ['required', 'integer', 'exists:organizacion,id'],
            'distrito_id' => ['required', 'integer', 'exists:organizacion,id'],
            'iglesia_id' => ['required', 'integer', 'exists:organizacion,id'],
            'club_id' => ['nullable', 'integer', 'exists:clubes,id'],
            'observacion' => ['nullable', 'string', 'max:500'],
        ]);

        $org = $this->aprobacion->relocate($organizacion, $request->user(), $data, $data['observacion'] ?? null);

        return ApiResponse::success($this->payload($org, true), 'Organización reubicada');
    }

    /**
     * @return array<string, mixed>
     */
    private function payload(Organizacion $org, bool $detailed = false): array
    {
        $data = [
            'id' => $org->id,
            'organizacion_padre_id' => $org->organizacion_padre_id,
            'tipo_organizacion_id' => $org->tipo_organizacion_id,
            'pais_id' => $org->pais_id,
            'departamento_id' => $org->departamento_id,
            'ciudad_id' => $org->ciudad_id,
            'nombre' => $org->nombre,
            'codigo' => $org->codigo,
            'direccion' => $org->direccion,
            'telefono' => $org->telefono,
            'correo' => $org->correo,
            'estado' => (bool) $org->estado,
            'estado_aprobacion' => $org->estado_aprobacion ?: Organizacion::APROBACION_APROBADA,
            'revision_observacion' => $org->revision_observacion,
            'revisado_por' => $org->revisado_por,
            'revisado_en' => optional($org->revisado_en)?->toIso8601String(),
            'fecha_creacion' => optional($org->fecha_creacion)?->toIso8601String(),
            'fecha_actualizacion' => optional($org->fecha_actualizacion)?->toIso8601String(),
            'tipo' => $org->relationLoaded('tipo') && $org->tipo
                ? ['id' => $org->tipo->id, 'nombre' => $org->tipo->nombre]
                : null,
            'padre' => $org->relationLoaded('padre') && $org->padre
                ? ['id' => $org->padre->id, 'nombre' => $org->padre->nombre]
                : null,
            'pais' => $org->relationLoaded('pais') && $org->pais
                ? ['id' => $org->pais->id, 'nombre' => $org->pais->nombre]
                : null,
            'departamento' => $org->relationLoaded('departamento') && $org->departamento
                ? ['id' => $org->departamento->id, 'nombre' => $org->departamento->nombre]
                : null,
            'ciudad' => $org->relationLoaded('ciudad') && $org->ciudad
                ? ['id' => $org->ciudad->id, 'nombre' => $org->ciudad->nombre]
                : null,
            'departamentos' => $org->relationLoaded('departamentos')
                ? $org->departamentos->map(fn ($dep) => [
                    'id' => $dep->id,
                    'codigo' => $dep->codigo,
                    'nombre' => $dep->nombre,
                    'label' => $dep->nombre,
                    'pais_id' => $dep->pais_id,
                ])->values()->all()
                : [],
            'ciudades' => $org->relationLoaded('ciudades')
                ? $org->ciudades->map(fn ($ciudad) => [
                    'id' => $ciudad->id,
                    'codigo' => $ciudad->codigo,
                    'nombre' => $ciudad->nombre,
                    'label' => $ciudad->nombre,
                    'departamento_id' => $ciudad->departamento_id,
                ])->values()->all()
                : [],
        ];

        if ($detailed && $org->relationLoaded('hijas')) {
            $data['hijas'] = $org->hijas->map(fn (Organizacion $hija) => [
                'id' => $hija->id,
                'nombre' => $hija->nombre,
            ])->values()->all();
        }

        return $data;
    }
}
