<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('roles') || ! Schema::hasTable('permissions')) {
            return;
        }

        $roleId = (int) DB::table('roles')->where('name', 'invitado')->value('id');
        $permissionId = (int) DB::table('permissions')->where('name', 'abonos.view')->value('id');
        if ($roleId === 0 || $permissionId === 0) {
            return;
        }

        $pivot = Schema::hasTable('permission_role')
            ? 'permission_role'
            : (Schema::hasTable('role_permission') ? 'role_permission' : null);
        if (! $pivot) {
            return;
        }

        $exists = DB::table($pivot)
            ->where('role_id', $roleId)
            ->where('permission_id', $permissionId)
            ->exists();
        if (! $exists) {
            DB::table($pivot)->insert([
                'role_id' => $roleId,
                'permission_id' => $permissionId,
            ]);
        }

        $this->forgetRolePermissionCache($roleId);
        Cache::forget('permissions:all:names');
    }

    public function down(): void
    {
        if (! Schema::hasTable('roles') || ! Schema::hasTable('permissions')) {
            return;
        }

        $roleId = (int) DB::table('roles')->where('name', 'invitado')->value('id');
        $permissionId = (int) DB::table('permissions')->where('name', 'abonos.view')->value('id');
        $pivot = Schema::hasTable('permission_role')
            ? 'permission_role'
            : (Schema::hasTable('role_permission') ? 'role_permission' : null);

        if ($roleId && $permissionId && $pivot) {
            DB::table($pivot)
                ->where('role_id', $roleId)
                ->where('permission_id', $permissionId)
                ->delete();
        }

        if ($roleId) {
            $this->forgetRolePermissionCache($roleId);
        }
        Cache::forget('permissions:all:names');
    }

    private function forgetRolePermissionCache(int $roleId): void
    {
        if (! Schema::hasTable('users') || ! Schema::hasColumn('users', 'active_rol_id')) {
            return;
        }

        $userIds = DB::table('users')->where('active_rol_id', $roleId)->pluck('id');
        foreach ($userIds as $userId) {
            Cache::forget("user:{$userId}:permissions");
            Cache::forget("user:{$userId}:permissions:all");
            Cache::forget("user:{$userId}:permissions:{$roleId}");
        }
    }
};
