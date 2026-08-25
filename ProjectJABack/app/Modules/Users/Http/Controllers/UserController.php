<?php

namespace App\Modules\Users\Http\Controllers;

use App\Models\User;
use App\Modules\Shared\Http\Responses\ApiResponse;
use App\Modules\Users\Http\Requests\StoreUserRequest;
use App\Modules\Users\Http\Requests\UpdateUserRequest;
use App\Modules\Users\Http\Requests\UploadAvatarRequest;
use App\Modules\Users\Models\Role;
use App\Modules\Users\Services\UserService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final class UserController
{
    public function __construct(private readonly UserService $userService) {}

    public function index(Request $request): JsonResponse
    {
        $this->authorizeViewAny($request);

        $paginator = $this->userService->list(
            $request->only(['q', 'role', 'is_active', 'organizacion_id', 'tipo_club']),
            min(20, max(1, (int) $request->integer('per_page', 15))),
            $request->user(),
        );

        $paginator->getCollection()->transform(fn (User $user) => $this->payload($user));

        return ApiResponse::fromPaginator($paginator);
    }

    public function store(StoreUserRequest $request): JsonResponse
    {
        $user = $this->userService->create($request->validated());

        return ApiResponse::success($this->payload($user), 'Usuario creado', Response::HTTP_CREATED);
    }

    public function show(Request $request, User $user): JsonResponse
    {
        abort_unless($request->user()->can('view', $user), Response::HTTP_FORBIDDEN);

        return ApiResponse::success($this->payload($user->load([
            'clubs',
            'persona.organizaciones.organizacion',
            'persona.organizaciones.rolesAsignados.rol',
        ])));
    }

    public function update(UpdateUserRequest $request, User $user): JsonResponse
    {
        $data = $request->validated();

        if (isset($data['role_ids']) && ! $request->user()->can('assignRoles', User::class)) {
            unset($data['role_ids']);
        }

        if (isset($data['club_ids']) && ! $request->user()->can('assignRoles', User::class) && ! $request->user()->can('update', $user)) {
            unset($data['club_ids']);
        }

        $user = $this->userService->update($user, $data);

        return ApiResponse::success($this->payload($user), 'Usuario actualizado');
    }

    public function destroy(Request $request, User $user): JsonResponse
    {
        abort_unless($request->user()->can('delete', $user), Response::HTTP_FORBIDDEN);
        $this->userService->delete($user);

        return ApiResponse::success(null, 'Usuario eliminado');
    }

    public function status(Request $request, User $user): JsonResponse
    {
        abort_unless($request->user()->can('update', $user), Response::HTTP_FORBIDDEN);
        $request->validate(['is_active' => ['required', 'boolean']]);
        $user = $this->userService->setStatus($user, $request->boolean('is_active'));

        return ApiResponse::success($this->payload($user), 'Estado actualizado');
    }

    public function roles(Request $request, User $user): JsonResponse
    {
        abort_unless($request->user()->can('assignRoles', User::class), Response::HTTP_FORBIDDEN);
        $data = $request->validate([
            'role_ids' => ['required', 'array'],
            'role_ids.*' => ['integer', 'exists:roles,id'],
        ]);
        $user = $this->userService->syncRoles($user, $data['role_ids']);

        return ApiResponse::success($this->payload($user), 'Roles actualizados');
    }

    public function avatar(UploadAvatarRequest $request, User $user): JsonResponse
    {
        $user = $this->userService->storeAvatarFile($user, $request->file('avatar'));

        return ApiResponse::success($this->payload($user), 'Avatar actualizado');
    }

    public function roleCatalog(Request $request): JsonResponse
    {
        abort_unless(
            $request->user()->hasPermission('users.view') || $request->user()->hasPermission('users.assign_roles'),
            Response::HTTP_FORBIDDEN
        );

        $roles = Role::query()->orderBy('sort_order')->orderBy('display_name')->get(['id', 'name', 'display_name']);

        return ApiResponse::success($roles);
    }

    private function authorizeViewAny(Request $request): void
    {
        abort_unless($request->user()->can('viewAny', User::class), Response::HTTP_FORBIDDEN);
    }

    private function payload(User $user): array
    {
        if (! $user->relationLoaded('clubs')) {
            $user->load('clubs');
        }
        if (! $user->relationLoaded('persona')) {
            $user->load([
                'persona.organizaciones.organizacion',
                'persona.organizaciones.rolesAsignados.rol',
            ]);
        } elseif ($user->persona && ! $user->persona->relationLoaded('organizaciones')) {
            $user->persona->load([
                'organizaciones.organizacion',
                'organizaciones.rolesAsignados.rol',
            ]);
        }

        $persona = $user->persona;

        $roleModels = Role::query()
            ->whereIn('name', $user->roleNames())
            ->get(['id', 'name', 'display_name']);

        return [
            'id' => $user->id,
            'name' => $user->name,
            'email' => $user->email,
            'avatar_url' => $this->publicAvatarUrl($user->avatar_url),
            'is_active' => $user->is_active,
            'is_super' => $user->isSuperAdmin(),
            'is_admin' => (bool) $user->is_admin,
            'provider' => $user->provider,
            'persona_id' => $user->persona_id,
            'persona' => $persona ? [
                'id' => $persona->id,
                'user_id' => $user->id,
                'tipo_identificacion' => $persona->tipo_identificacion,
                'identificacion' => $persona->identificacion,
                'nombre1' => $persona->nombre1,
                'nombre2' => $persona->nombre2,
                'apellido1' => $persona->apellido1,
                'apellido2' => $persona->apellido2,
                'correo' => $persona->correo,
                'telefono' => $persona->telefono,
                'full_name' => $persona->full_name,
            ] : null,
            'organizaciones' => $persona && $persona->relationLoaded('organizaciones')
                ? $persona->organizaciones
                    ->filter(fn ($po) => (bool) $po->estado)
                    ->map(fn ($po) => [
                        'id' => $po->id,
                        'organizacion_id' => $po->organizacion_id,
                        'organizacion_nombre' => $po->organizacion?->nombre,
                        'fecha_inicio' => optional($po->fecha_inicio)?->format('Y-m-d'),
                        'fecha_fin' => optional($po->fecha_fin)?->format('Y-m-d'),
                        'estado' => (bool) $po->estado,
                        'roles' => $po->relationLoaded('rolesAsignados')
                            ? $po->rolesAsignados->map(fn ($r) => [
                                'id' => $r->id,
                                'rol_id' => $r->rol_id,
                                'rol_nombre' => $r->rol?->display_name ?: $r->rol?->name,
                            ])->values()->all()
                            : [],
                    ])->values()->all()
                : [],
            'roles' => $roleModels->map(fn (Role $role) => [
                'id' => $role->id,
                'name' => $role->name,
                'display_name' => $role->display_name,
            ])->values()->all(),
            'club_ids' => $user->clubs->pluck('id')->values()->all(),
            'clubs' => $user->clubs->map(fn ($club) => [
                'id' => $club->id,
                'nombre' => $club->nombre,
                'distrito' => $club->distrito,
                'ciudad' => $club->ciudad,
                'logo_url' => $club->logo,
                'tipos' => array_values($club->tipos ?? []),
            ])->values()->all(),
            'created_at' => $user->created_at,
            'updated_at' => $user->updated_at,
        ];
    }

    private function publicAvatarUrl(?string $value): ?string
    {
        if (! is_string($value) || $value === '') {
            return null;
        }

        if (str_starts_with($value, 'http://') || str_starts_with($value, 'https://')) {
            return $value;
        }

        $path = ltrim($value, '/');
        if (str_starts_with($path, 'storage/')) {
            return url($path);
        }

        return url('storage/'.$path);
    }
}
