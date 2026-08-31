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
        $pageKey = 'lugares';

        $pageId = DB::table('pages')->where('key', $pageKey)->value('id');
        $pagePayload = [
            'name' => 'Lugares',
            'route_name' => 'lugares',
            'icon' => 'pi pi-map-marker',
            'sort_order' => 56,
            'is_active' => true,
            'description' => 'Catálogo de lugares con mapa; agrupa terrenos y cabañas',
            'updated_at' => $now,
        ];

        if (! $pageId) {
            $pageId = DB::table('pages')->insertGetId([
                'key' => $pageKey,
                ...$pagePayload,
                'created_at' => $now,
            ]);
        } else {
            DB::table('pages')->where('id', $pageId)->update($pagePayload);
        }

        $actions = [
            ['action' => 'view', 'display_name' => 'Ver lugares', 'sort_order' => 1],
            ['action' => 'create', 'display_name' => 'Crear lugares', 'sort_order' => 2],
            ['action' => 'update', 'display_name' => 'Actualizar lugares', 'sort_order' => 3],
            ['action' => 'delete', 'display_name' => 'Eliminar lugares', 'sort_order' => 4],
        ];

        $permissionIds = [];
        foreach ($actions as $perm) {
            $name = "{$pageKey}.{$perm['action']}";
            $existingId = DB::table('permissions')->where('name', $name)->value('id');
            $permPayload = [
                'display_name' => $perm['display_name'],
                'module' => $pageKey,
                'page_id' => $pageId,
                'action' => $perm['action'],
                'sort_order' => $perm['sort_order'],
                'updated_at' => $now,
            ];

            if ($existingId) {
                DB::table('permissions')->where('id', $existingId)->update($permPayload);
                $permissionIds[] = (int) $existingId;
            } else {
                $permissionIds[] = (int) DB::table('permissions')->insertGetId([
                    'name' => $name,
                    ...$permPayload,
                    'created_at' => $now,
                ]);
            }
        }

        $pivot = Schema::hasTable('permission_role')
            ? 'permission_role'
            : (Schema::hasTable('role_permission') ? 'role_permission' : null);

        $roleIds = DB::table('roles')
            ->where(function ($query) {
                $query->where('name', 'admin')->orWhere('is_super', true);
            })
            ->pluck('id');

        if ($pivot && $roleIds->isNotEmpty()) {
            foreach ($roleIds as $roleId) {
                foreach ($permissionIds as $permissionId) {
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
                }
            }
        }

        Cache::forget('permissions:all:names');
    }

    public function down(): void
    {
        if (! Schema::hasTable('permissions')) {
            return;
        }

        $permissionIds = DB::table('permissions')
            ->where('module', 'lugares')
            ->pluck('id');

        $pivot = Schema::hasTable('permission_role')
            ? 'permission_role'
            : (Schema::hasTable('role_permission') ? 'role_permission' : null);

        if ($permissionIds->isNotEmpty() && $pivot) {
            DB::table($pivot)->whereIn('permission_id', $permissionIds)->delete();
        }

        DB::table('permissions')->where('module', 'lugares')->delete();

        if (Schema::hasTable('pages')) {
            DB::table('pages')->where('key', 'lugares')->delete();
        }

        Cache::forget('permissions:all:names');
    }
};
