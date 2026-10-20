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
        $pageId = DB::table('pages')->where('key', 'finanzas')->value('id');
        $payload = [
            'name' => 'Finanzas',
            'route_name' => 'finanzas',
            'icon' => 'pi pi-wallet',
            'sort_order' => 42,
            'is_active' => true,
            'description' => 'Caja, ingresos, gastos y distribución del club',
            'updated_at' => $now,
        ];
        if (Schema::hasColumn('pages', 'front')) {
            $payload['front'] = 'clubes';
        }

        if (! $pageId) {
            $pageId = DB::table('pages')->insertGetId([
                'key' => 'finanzas',
                ...$payload,
                'created_at' => $now,
            ]);
        } else {
            DB::table('pages')->where('id', $pageId)->update($payload);
        }

        $permissionIds = [];
        foreach ([
            ['action' => 'view', 'display_name' => 'Ver finanzas', 'sort_order' => 1],
            ['action' => 'update', 'display_name' => 'Registrar movimientos de caja', 'sort_order' => 2],
        ] as $perm) {
            $name = "finanzas.{$perm['action']}";
            $existingId = DB::table('permissions')->where('name', $name)->value('id');
            $permPayload = [
                'display_name' => $perm['display_name'],
                'module' => 'finanzas',
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
        if (! $pivot) {
            Cache::forget('permissions:all:names');

            return;
        }

        $roleIds = DB::table('roles')
            ->where(function ($query) {
                $query->whereIn('name', ['admin', 'director', 'subdirector', 'secretario', 'tesorero'])
                    ->orWhere('is_super', true);
            })
            ->pluck('id');

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

        Cache::forget('permissions:all:names');
    }

    public function down(): void
    {
        if (! Schema::hasTable('permissions')) {
            return;
        }

        $permissionIds = DB::table('permissions')->where('module', 'finanzas')->pluck('id');
        $pivot = Schema::hasTable('permission_role')
            ? 'permission_role'
            : (Schema::hasTable('role_permission') ? 'role_permission' : null);
        if ($permissionIds->isNotEmpty() && $pivot) {
            DB::table($pivot)->whereIn('permission_id', $permissionIds)->delete();
        }
        DB::table('permissions')->where('module', 'finanzas')->delete();
        if (Schema::hasTable('pages')) {
            DB::table('pages')->where('key', 'finanzas')->delete();
        }
        Cache::forget('permissions:all:names');
    }
};
