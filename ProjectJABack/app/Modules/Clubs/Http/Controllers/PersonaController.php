<?php

namespace App\Modules\Clubs\Http\Controllers;

use App\Modules\Clubs\Http\Requests\StorePersonaRequest;
use App\Modules\Clubs\Http\Requests\UpdatePersonaRequest;
use App\Modules\Clubs\Models\Club;
use App\Modules\Clubs\Models\Persona;
use App\Modules\Clubs\Services\PersonaService;
use App\Modules\Shared\Http\Responses\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final class PersonaController
{
    public function __construct(private readonly PersonaService $personaService) {}

    public function index(Request $request): JsonResponse
    {
        abort_unless($request->user()->can('viewAny', Persona::class), Response::HTTP_FORBIDDEN);

        $paginator = $this->personaService->list(
            $request->user(),
            $request->only(['q', 'sin_usuario', 'organizacion_id', 'organizacion_padre_id', 'solo_tipo_club']),
            (int) $request->integer('per_page', 20),
        );
        $paginator->getCollection()->transform(fn (Persona $persona) => $this->payload($persona));

        return ApiResponse::fromPaginator($paginator);
    }

    public function organizacionOptions(Request $request): JsonResponse
    {
        abort_unless($request->user()->can('create', Persona::class) || $request->user()->can('viewAny', Persona::class), Response::HTTP_FORBIDDEN);

        return ApiResponse::success($this->personaService->organizacionOptions(
            $request->user(),
            filter_var($request->query('solo_tipo_club', false), FILTER_VALIDATE_BOOLEAN),
        ));
    }

    public function store(StorePersonaRequest $request): JsonResponse
    {
        $persona = $this->personaService->create($request->validated(), $request->user());

        return ApiResponse::success($this->payload($persona), 'Persona creada', Response::HTTP_CREATED);
    }

    public function show(Request $request, Persona $persona): JsonResponse
    {
        abort_unless($request->user()->can('view', $persona), Response::HTTP_FORBIDDEN);
        $persona->loadMissing([
            'user:id,persona_id,name,email',
            'organizaciones.organizacion:id,nombre,codigo',
        ]);

        return ApiResponse::success($this->payload($persona));
    }

    public function update(UpdatePersonaRequest $request, Persona $persona): JsonResponse
    {
        $persona = $this->personaService->update($persona, $request->validated(), $request->user());

        return ApiResponse::success($this->payload($persona), 'Persona actualizada');
    }

    public function destroy(Request $request, Persona $persona): JsonResponse
    {
        abort_unless($request->user()->can('delete', $persona), Response::HTTP_FORBIDDEN);
        $this->personaService->delete($persona);

        return ApiResponse::success(null, 'Persona eliminada');
    }

    private function payload(Persona $persona): array
    {
        $clubs = $persona->clubsViaOrganizacion();

        $organizaciones = $persona->relationLoaded('organizaciones')
            ? $persona->organizaciones
            : $persona->organizaciones()->with('organizacion:id,nombre,codigo')->get();

        return [
            'id' => $persona->id,
            'user_id' => $persona->relationLoaded('user')
                ? $persona->user?->id
                : $persona->user()->value('users.id'),
            'tipo_identificacion' => $persona->tipo_identificacion,
            'identificacion' => $persona->identificacion,
            'nombre1' => $persona->nombre1,
            'nombre2' => $persona->nombre2,
            'apellido1' => $persona->apellido1,
            'apellido2' => $persona->apellido2,
            'fecha_nacimiento' => $persona->fecha_nacimiento?->format('Y-m-d'),
            'sexo' => $persona->sexo,
            'telefono' => $persona->telefono,
            'correo' => $persona->correo,
            'direccion_actual' => $persona->direccion_actual,
            'full_name' => $persona->full_name,
            'club_ids' => $clubs->pluck('id')->values()->all(),
            'clubs' => $clubs->map(fn (Club $club) => [
                'id' => $club->id,
                'nombre' => $club->nombre,
                'distrito' => $club->distrito,
                'ciudad' => $club->ciudad,
                'tipos' => array_values($club->tipos ?? []),
            ])->values()->all(),
            'organizacion_ids' => $organizaciones
                ->where('estado', true)
                ->pluck('organizacion_id')
                ->map(fn ($id) => (int) $id)
                ->values()
                ->all(),
            'organizaciones' => $organizaciones->map(fn ($po) => [
                'id' => $po->id,
                'organizacion_id' => $po->organizacion_id,
                'organizacion_nombre' => $po->organizacion?->nombre,
                'estado' => (bool) $po->estado,
            ])->values()->all(),
            'created_at' => $persona->created_at,
            'updated_at' => $persona->updated_at,
        ];
    }
}
