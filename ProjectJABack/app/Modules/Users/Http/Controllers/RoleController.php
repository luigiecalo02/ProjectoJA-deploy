<?php

namespace App\Modules\Users\Http\Controllers;

use App\Modules\Shared\Http\Responses\ApiResponse;
use App\Modules\Users\Models\Role;
use App\Modules\Users\Services\RoleService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final class RoleController
{
    public function __construct(private readonly RoleService $roleService) {}

    public function index(Request $request): JsonResponse
    {
        abort_unless($request->user()->can('viewAny', Role::class), Response::HTTP_FORBIDDEN);

        $roles = collect($this->roleService->list())->map(fn (Role $role) => $this->payload($role));

        return ApiResponse::success($roles);
    }

    public function show(Request $request, Role $role): JsonResponse
    {
        abort_unless($request->user()->can('view', $role), Response::HTTP_FORBIDDEN);

        return ApiResponse::success($this->payload($this->roleService->find($role->id), true));
    }

    public function store(Request $request): JsonResponse
    {
        abort_unless($request->user()->can('create', Role::class), Response::HTTP_FORBIDDEN);

        $data = $request->validate([
            'display_name' => ['required', 'string', 'max:255'],
            'name' => ['nullable', 'string', 'max:100', 'alpha_dash', 'unique:roles,name'],
            'description' => ['nullable', 'string', 'max:1000'],
            'icon' => ['nullable', 'string', 'max:100'],
            'permission_ids' => ['sometimes', 'array'],
            'permission_ids.*' => ['integer', 'exists:permissions,id'],
        ]);

        $role = $this->roleService->create($data);

        return ApiResponse::success($this->payload($role, true), 'Rol creado', Response::HTTP_CREATED);
    }

    public function update(Request $request, Role $role): JsonResponse
    {
        abort_unless($request->user()->can('update', $role), Response::HTTP_FORBIDDEN);

        $data = $request->validate([
            'display_name' => ['sometimes', 'required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:1000'],
            'icon' => ['nullable', 'string', 'max:100'],
            'permission_ids' => ['sometimes', 'array'],
            'permission_ids.*' => ['integer', 'exists:permissions,id'],
        ]);

        if (isset($data['permission_ids'])) {
            abort_unless($request->user()->can('assignPermissions', $role), Response::HTTP_FORBIDDEN);
        }

        $role = $this->roleService->update($role, $data);

        return ApiResponse::success($this->payload($role, true), 'Rol actualizado');
    }

    public function destroy(Request $request, Role $role): JsonResponse
    {
        abort_unless($request->user()->can('delete', $role), Response::HTTP_FORBIDDEN);
        $this->roleService->delete($role);

        return ApiResponse::success(null, 'Rol eliminado');
    }

    public function permissions(Request $request, Role $role): JsonResponse
    {
        abort_unless($request->user()->can('assignPermissions', $role), Response::HTTP_FORBIDDEN);

        $data = $request->validate([
            'permission_ids' => ['required', 'array'],
            'permission_ids.*' => ['integer', 'exists:permissions,id'],
        ]);

        $role = $this->roleService->syncPermissions($role, $data['permission_ids']);

        return ApiResponse::success($this->payload($role, true), 'Permisos actualizados');
    }

    public function pages(Request $request): JsonResponse
    {
        abort_unless(
            $request->user()->hasPermission('roles.view') || $request->user()->hasPermission('roles.assign_permissions'),
            Response::HTTP_FORBIDDEN
        );

        $pages = collect($this->roleService->pagesCatalog())->map(fn ($page) => [
            'id' => $page->id,
            'key' => $page->key,
            'name' => $page->name,
            'route_name' => $page->route_name,
            'icon' => $page->icon,
            'description' => $page->description,
            'permissions' => $page->permissions->map(fn ($p) => [
                'id' => $p->id,
                'name' => $p->name,
                'display_name' => $p->display_name,
                'action' => $p->action,
            ]),
        ]);

        return ApiResponse::success($pages);
    }

    private function payload(Role $role, bool $withPermissions = false): array
    {
        $data = [
            'id' => $role->id,
            'name' => $role->name,
            'display_name' => $role->display_name,
            'description' => $role->description,
            'icon' => $role->icon,
            'is_system' => (bool) $role->is_system,
            'is_super' => (bool) $role->is_super,
            'users_count' => $role->users_count ?? app(RoleService::class)->usersCountForRole($role),
            'permissions_count' => $role->permissions_count ?? $role->permissions()->count(),
        ];

        if ($withPermissions || $role->relationLoaded('permissions')) {
            $data['permission_ids'] = $role->permissions->pluck('id')->values()->all();
            $data['permissions'] = $role->permissions->map(fn ($p) => [
                'id' => $p->id,
                'name' => $p->name,
                'display_name' => $p->display_name,
            ])->values()->all();
        }

        return $data;
    }
}
