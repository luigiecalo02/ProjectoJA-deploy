<?php

namespace App\Modules\Users\Services;

use App\Models\User;
use App\Modules\Shared\Services\AuditLogger;
use App\Modules\Users\Models\Page;
use App\Modules\Users\Models\Role;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

final class RoleService
{
    public function __construct(private readonly AuditLogger $auditLogger) {}

    public function list(): array
    {
        return Role::query()
            ->withCount(['permissions'])
            ->with(['permissions:id,name'])
            ->orderByDesc('is_super')
            ->orderBy('display_name')
            ->get()
            ->each(function (Role $role) {
                $role->setAttribute('users_count', $this->usersCountForRole($role));
            })
            ->all();
    }

    public function find(int $id): Role
    {
        $role = Role::query()->with(['permissions'])->findOrFail($id);
        $role->setAttribute('users_count', $this->usersCountForRole($role));
        $role->loadCount('permissions');

        return $role;
    }

    public function create(array $data): Role
    {
        return DB::transaction(function () use ($data) {
            $permissionIds = $data['permission_ids'] ?? [];
            unset($data['permission_ids']);

            $data['name'] = $data['name'] ?? Str::slug($data['display_name'], '_');
            $data['is_system'] = false;
            $data['is_super'] = false;

            $role = Role::query()->create($data);
            $role->permissions()->sync($permissionIds);

            $this->auditLogger->log('roles', 'create', null, $role->load('permissions')->toArray(), $role);

            $role = $role->load('permissions')->loadCount('permissions');
            $role->setAttribute('users_count', 0);

            return $role;
        });
    }

    public function update(Role $role, array $data): Role
    {
        if ($role->is_super) {
            throw ValidationException::withMessages([
                'role' => ['El rol Super Administrador no se puede modificar.'],
            ]);
        }

        return DB::transaction(function () use ($role, $data) {
            $old = $role->load('permissions')->toArray();
            $permissionIds = $data['permission_ids'] ?? null;
            unset($data['permission_ids'], $data['is_super'], $data['is_system'], $data['name']);

            $role->update($data);

            if (is_array($permissionIds)) {
                $role->permissions()->sync($permissionIds);
                $this->clearUsersPermissionCache($role);
            }

            Cache::forget('permissions:all:names');
            $role = $role->fresh(['permissions'])->loadCount('permissions');
            $role->setAttribute('users_count', $this->usersCountForRole($role));
            $this->auditLogger->log('roles', 'update', $old, $role->toArray(), $role);

            return $role;
        });
    }

    public function delete(Role $role): void
    {
        if ($role->is_system || $role->is_super) {
            throw ValidationException::withMessages([
                'role' => ['No se puede eliminar un rol de sistema.'],
            ]);
        }

        if ($this->usersCountForRole($role) > 0) {
            throw ValidationException::withMessages([
                'role' => ['El rol tiene usuarios asignados.'],
            ]);
        }

        $old = $role->toArray();
        $role->permissions()->detach();
        $role->delete();
        $this->auditLogger->log('roles', 'delete', $old, null, $role);
    }

    public function syncPermissions(Role $role, array $permissionIds): Role
    {
        if ($role->is_super) {
            throw ValidationException::withMessages([
                'role' => ['El Super Administrador ya tiene acceso total.'],
            ]);
        }

        $old = $role->permissions->pluck('id')->all();
        $role->permissions()->sync($permissionIds);
        $this->clearUsersPermissionCache($role);
        Cache::forget('permissions:all:names');

        $role = $role->fresh(['permissions'])->loadCount('permissions');
        $role->setAttribute('users_count', $this->usersCountForRole($role));
        $this->auditLogger->log('roles', 'assign_permissions', ['permission_ids' => $old], [
            'permission_ids' => $permissionIds,
        ], $role);

        return $role;
    }

    public function pagesCatalog(): array
    {
        return Page::query()
            ->where('is_active', true)
            ->with(['permissions' => fn ($q) => $q->orderBy('sort_order')])
            ->orderBy('sort_order')
            ->get()
            ->all();
    }

    public function usersCountForRole(Role $role): int
    {
        if ($role->is_super) {
            return User::query()->where('is_super', true)->count();
        }

        if ($role->name === 'admin') {
            return User::query()->where('is_admin', true)->count();
        }

        return (int) DB::table('persona_organizacion_rol')
            ->where('rol_id', $role->id)
            ->distinct()
            ->count('persona_organizacion_id');
    }

    private function clearUsersPermissionCache(Role $role): void
    {
        if ($role->is_super) {
            User::query()->where('is_super', true)->each(fn (User $user) => $user->clearPermissionCache());

            return;
        }

        if ($role->name === 'admin') {
            User::query()->where('is_admin', true)->each(fn (User $user) => $user->clearPermissionCache());

            return;
        }

        $personaIds = DB::table('persona_organizacion_rol')
            ->join(
                'persona_organizacion',
                'persona_organizacion.id',
                '=',
                'persona_organizacion_rol.persona_organizacion_id'
            )
            ->where('persona_organizacion_rol.rol_id', $role->id)
            ->pluck('persona_organizacion.persona_id');

        if ($personaIds->isEmpty()) {
            return;
        }

        User::query()
            ->whereIn('persona_id', $personaIds)
            ->each(fn (User $user) => $user->clearPermissionCache());
    }
}
