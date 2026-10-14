<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const NAME = 'mis_abonos.update';

    public function up(): void
    {
        if (! Schema::hasTable('pages') || ! Schema::hasTable('permissions')) {
            return;
        }

        $now = now();
        $pageId = DB::table('pages')->where('key', 'mis_abonos')->value('id');
        $payload = [
            'display_name' => 'Registrar abonos',
            'module' => 'mis_abonos',
            'page_id' => $pageId,
            'action' => 'update',
            'sort_order' => 2,
            'updated_at' => $now,
        ];

        $permissionId = DB::table('permissions')->where('name', self::NAME)->value('id');
        if ($permissionId) {
            DB::table('permissions')->where('id', $permissionId)->update($payload);
            $permissionId = (int) $permissionId;
        } else {
            $permissionId = (int) DB::table('permissions')->insertGetId([
                'name' => self::NAME,
                ...$payload,
                'created_at' => $now,
            ]);
        }

        $pivot = Schema::hasTable('permission_role')
            ? 'permission_role'
            : (Schema::hasTable('role_permission') ? 'role_permission' : null);

        if ($pivot) {
            $roleIds = DB::table('roles')
                ->where(function ($query) {
                    $query
                        ->whereIn('name', [
                            'admin',
                            'director',
                            'subdirector',
                            'secretario',
                            'tesorero',
                            'miembro',
                            'invitado',
                        ])
                        ->orWhere('is_super', true);
                })
                ->pluck('id')
                ->map(fn ($id) => (int) $id)
                ->all();

            foreach ($roleIds as $roleId) {
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
            }
        }

        Cache::forget('permissions:all:names');
    }

    public function down(): void
    {
        if (! Schema::hasTable('permissions')) {
            return;
        }

        $permissionId = DB::table('permissions')->where('name', self::NAME)->value('id');
        $pivot = Schema::hasTable('permission_role')
            ? 'permission_role'
            : (Schema::hasTable('role_permission') ? 'role_permission' : null);

        if ($permissionId && $pivot) {
            DB::table($pivot)->where('permission_id', $permissionId)->delete();
        }

        DB::table('permissions')->where('name', self::NAME)->delete();
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
