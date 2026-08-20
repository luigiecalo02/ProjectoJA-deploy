<?php

namespace App\Modules\Clubs\Http\Controllers;

use App\Modules\Clubs\Http\Requests\StoreClubRequest;
use App\Modules\Clubs\Http\Requests\SyncClubDirectorsRequest;
use App\Modules\Clubs\Http\Requests\UpdateClubRequest;
use App\Modules\Clubs\Models\Club;
use App\Modules\Clubs\Models\Persona;
use App\Modules\Clubs\Services\ClubService;
use App\Modules\Shared\Http\Responses\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final class ClubController
{
    public function __construct(private readonly ClubService $clubService) {}

    public function index(Request $request): JsonResponse
    {
        abort_unless($request->user()->can('viewAny', Club::class), Response::HTTP_FORBIDDEN);

        $paginator = $this->clubService->list(
            $request->user(),
            $request->only(['q', 'is_active']),
            (int) $request->integer('per_page', 15),
        );
        $paginator->getCollection()->transform(fn (Club $club) => $this->payload($club));

        return ApiResponse::fromPaginator($paginator);
    }

    public function availableForAccount(Request $request): JsonResponse
    {
        abort_unless(
            $request->user()->hasPermission('users.view')
                || $request->user()->hasPermission('users.assign_roles')
                || $request->user()->hasPermission('clubs.view'),
            Response::HTTP_FORBIDDEN
        );

        $data = $request->validate([
            'user_id' => ['nullable', 'integer', 'exists:users,id'],
        ]);

        $clubs = collect($this->clubService->availableForAccount(
            isset($data['user_id']) ? (int) $data['user_id'] : null
        ))->map(fn (Club $club) => $this->payload($club));

        return ApiResponse::success($clubs);
    }

    public function iglesiaOptions(Request $request): JsonResponse
    {
        $user = $request->user();
        abort_unless(
            $user->can('create', Club::class)
                || $user->can('viewAny', Club::class)
                || $user->hasPermission('clubs.update')
                || $user->hasPermission('mi_club.update'),
            Response::HTTP_FORBIDDEN
        );

        return ApiResponse::success($this->clubService->iglesiaOptions($user));
    }

    public function current(Request $request): JsonResponse
    {
        $user = $request->user();
        abort_unless($user->can('viewAny', Club::class), Response::HTTP_FORBIDDEN);

        $club = $this->clubService->currentForActor($user);
        abort_unless($club && $user->can('view', $club), Response::HTTP_NOT_FOUND);

        return ApiResponse::success($this->payload($this->clubService->find($club->id), true));
    }

    public function store(StoreClubRequest $request): JsonResponse
    {
        $club = $this->clubService->create($request->user(), $request->validated());

        return ApiResponse::success($this->payload($club, true), 'Club creado', Response::HTTP_CREATED);
    }

    public function show(Request $request, Club $club): JsonResponse
    {
        abort_unless($request->user()->can('view', $club), Response::HTTP_FORBIDDEN);

        return ApiResponse::success($this->payload($this->clubService->find($club->id), true));
    }

    public function update(UpdateClubRequest $request, Club $club): JsonResponse
    {
        $club = $this->clubService->update($club, $request->validated(), $request->user());

        return ApiResponse::success($this->payload($club, true), 'Club actualizado');
    }

    public function destroy(Request $request, Club $club): JsonResponse
    {
        abort_unless($request->user()->can('delete', $club), Response::HTTP_FORBIDDEN);
        $this->clubService->delete($club);

        return ApiResponse::success(null, 'Club eliminado');
    }

    public function logo(Request $request, Club $club): JsonResponse
    {
        abort_unless($request->user()->can('update', $club), Response::HTTP_FORBIDDEN);
        $request->validate([
            'logo' => ['required', 'image', 'mimes:jpg,jpeg,png,webp,gif', 'max:5120'],
        ]);

        $club = $this->clubService->storeLogo($club, $request->file('logo'), $request->user());

        return ApiResponse::success($this->payload($club, true), 'Logo actualizado');
    }

    public function members(Request $request, Club $club): JsonResponse
    {
        abort_unless($request->user()->can('manageMembers', $club), Response::HTTP_FORBIDDEN);
        $data = $request->validate([
            'persona_ids' => ['required', 'array'],
            'persona_ids.*' => ['integer', 'exists:personas,id'],
        ]);

        $club = $this->clubService->syncMembers($club, $data['persona_ids']);

        return ApiResponse::success($this->payload($club, true), 'Integrantes actualizados');
    }

    public function directors(SyncClubDirectorsRequest $request, Club $club): JsonResponse
    {
        $club = $this->clubService->syncDirectors($club, $request->validated()['directors'], $request->user());

        return ApiResponse::success($this->payload($club, true), 'Directores actualizados');
    }

    public function directorsCatalog(Request $request): JsonResponse
    {
        abort_unless(
            $request->user()->hasPermission('clubs.view')
                || $request->user()->hasPermission('clubs.manage_directors')
                || $request->user()->hasPermission('mi_club.view')
                || $request->user()->hasPermission('mi_club.manage_directors')
                || $request->user()->hasPermission('mi_club.update'),
            Response::HTTP_FORBIDDEN
        );

        $data = $request->validate([
            'position' => ['required', 'in:director,subdirector,secretaria,tesorero'],
            'club_id' => ['nullable', 'integer', 'exists:clubes,id'],
        ]);

        $tipos = null;
        $clubId = isset($data['club_id']) ? (int) $data['club_id'] : null;
        if ($clubId) {
            $tipos = Club::query()->find($clubId)?->tipos;
        }

        $users = collect($this->clubService->directorsCatalog($data['position'], $tipos, $clubId))->map(fn ($user) => [
            'id' => $user->id,
            'name' => $user->name,
            'email' => $user->email,
        ]);

        return ApiResponse::success($users);
    }

    private function payload(Club $club, bool $detailed = false): array
    {
        $directors = $this->clubService->boardAssignments($club);

        $data = [
            'id' => $club->id,
            'organizacion_id' => $club->organizacion_id,
            'iglesia_organizacion_id' => $club->relationLoaded('organizacion')
                ? ($club->organizacion?->organizacion_padre_id)
                : null,
            'organizacion' => $club->relationLoaded('organizacion') && $club->organizacion
                ? [
                    'id' => $club->organizacion->id,
                    'nombre' => $club->organizacion->nombre,
                    'codigo' => $club->organizacion->codigo,
                    'tipo_organizacion_id' => $club->organizacion->tipo_organizacion_id,
                    'organizacion_padre_id' => $club->organizacion->organizacion_padre_id,
                    'padre' => $club->organizacion->relationLoaded('padre') && $club->organizacion->padre
                        ? [
                            'id' => $club->organizacion->padre->id,
                            'nombre' => $club->organizacion->padre->nombre,
                            'codigo' => $club->organizacion->padre->codigo,
                        ]
                        : null,
                ]
                : null,
            'nombre' => $club->nombre,
            'nombre_corto' => $club->nombre_corto,
            'lema' => $club->lema,
            'logo' => $club->logo,
            'logo_url' => $club->logo,
            'fecha_fundacion' => optional($club->fecha_fundacion)?->format('Y-m-d'),
            'descripcion' => $club->descripcion,
            'color_principal' => $club->color_principal,
            'color_secundario' => $club->color_secundario,
            'sitio_web' => $club->sitio_web,
            'distrito' => $club->distrito,
            'ciudad' => $club->ciudad,
            'tipos' => array_values($club->tipos ?? []),
            'is_active' => (bool) $club->is_active,
            'account_user_id' => $club->relationLoaded('users')
                ? $club->users->first()?->id
                : $club->users()->value('users.id'),
            'personas_count' => $club->personas_count ?? null,
            'directors' => $directors,
            'created_at' => $club->created_at,
            'updated_at' => $club->updated_at,
        ];

        if ($detailed) {
            $personas = $this->clubService->memberPersonas($club);
            $data['persona_ids'] = $personas->pluck('id')->values()->all();
            $data['personas'] = $personas->map(function (Persona $persona) {
                $userId = $persona->relationLoaded('user')
                    ? $persona->user?->id
                    : $persona->user()->value('users.id');

                return [
                    'id' => $persona->id,
                    'user_id' => $userId,
                    'tipo_identificacion' => $persona->tipo_identificacion,
                    'identificacion' => $persona->identificacion,
                    'nombre1' => $persona->nombre1,
                    'nombre2' => $persona->nombre2,
                    'apellido1' => $persona->apellido1,
                    'apellido2' => $persona->apellido2,
                    'correo' => $persona->correo,
                    'telefono' => $persona->telefono,
                    'full_name' => $persona->full_name,
                    'cargo' => null,
                ];
            })->values()->all();
            $data['personas_count'] = $personas->count();
        }

        return $data;
    }
}
