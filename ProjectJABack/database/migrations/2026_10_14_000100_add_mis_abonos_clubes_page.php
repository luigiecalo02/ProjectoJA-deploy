<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('pages') || ! Schema::hasTable('permissions')) {
            return;
        }

        $now = now();
        $permissionId = $this->upsertPage($now);

        $pivot = Schema::hasTable('permission_role')
            ? 'permission_role'
            : (Schema::hasTable('role_permission') ? 'role_permission' : null);

        if ($pivot && $permissionId) {
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

        $permissionIds = DB::table('permissions')->where('module', 'mis_abonos')->pluck('id');
        $pivot = Schema::hasTable('permission_role')
            ? 'permission_role'
            : (Schema::hasTable('role_permission') ? 'role_permission' : null);

        if ($permissionIds->isNotEmpty() && $pivot) {
            DB::table($pivot)->whereIn('permission_id', $permissionIds)->delete();
        }

        DB::table('permissions')->where('module', 'mis_abonos')->delete();

        if (Schema::hasTable('pages')) {
            DB::table('pages')->where('key', 'mis_abonos')->delete();
        }

        Cache::forget('permissions:all:names');
    }

    private function upsertPage(mixed $now): int
    {
        $pageId = DB::table('pages')->where('key', 'mis_abonos')->value('id');
        $payload = [
            'name' => 'Mis abonos',
            'route_name' => 'mis-abonos',
            'icon' => 'pi pi-wallet',
            'sort_order' => 44,
            'is_active' => true,
            'description' => 'Abonos de la persona en sesión',
            'updated_at' => $now,
        ];

        if (Schema::hasColumn('pages', 'front')) {
            $payload['front'] = 'clubes';
        }

        if (! $pageId) {
            $pageId = DB::table('pages')->insertGetId([
                'key' => 'mis_abonos',
                ...$payload,
                'created_at' => $now,
            ]);
        } else {
            DB::table('pages')->where('id', $pageId)->update($payload);
        }

        $name = 'mis_abonos.view';
        $existingId = DB::table('permissions')->where('name', $name)->value('id');
        $permPayload = [
            'display_name' => 'Ver mis abonos',
            'module' => 'mis_abonos',
            'page_id' => $pageId,
            'action' => 'view',
            'sort_order' => 1,
            'updated_at' => $now,
        ];

        if ($existingId) {
            DB::table('permissions')->where('id', $existingId)->update($permPayload);

            return (int) $existingId;
        }

        return (int) DB::table('permissions')->insertGetId([
            'name' => $name,
            ...$permPayload,
            'created_at' => $now,
        ]);
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
