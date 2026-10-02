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
        $pageId = DB::table('pages')->where('key', 'presupuesto')->value('id');
        $payload = [
            'name' => 'Presupuesto',
            'route_name' => 'presupuesto',
            'icon' => 'pi pi-list',
            'sort_order' => 45,
            'is_active' => true,
            'description' => 'Presupuesto de campamentos del club',
            'updated_at' => $now,
        ];
        if (Schema::hasColumn('pages', 'front')) {
            $payload['front'] = 'clubes';
        }

        if (! $pageId) {
            $pageId = DB::table('pages')->insertGetId([
                'key' => 'presupuesto',
                ...$payload,
                'created_at' => $now,
            ]);
        } else {
            DB::table('pages')->where('id', $pageId)->update($payload);
        }

        $permissionIds = [];
        foreach ([
            ['action' => 'view', 'display_name' => 'Ver presupuesto', 'sort_order' => 1],
            ['action' => 'update', 'display_name' => 'Editar presupuesto', 'sort_order' => 2],
        ] as $perm) {
            $name = "presupuesto.{$perm['action']}";
            $existingId = DB::table('permissions')->where('name', $name)->value('id');
            $permPayload = [
                'display_name' => $perm['display_name'],
                'module' => 'presupuesto',
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

        $permissionIds = DB::table('permissions')->where('module', 'presupuesto')->pluck('id');
        $pivot = Schema::hasTable('permission_role')
            ? 'permission_role'
            : (Schema::hasTable('role_permission') ? 'role_permission' : null);
        if ($permissionIds->isNotEmpty() && $pivot) {
            DB::table($pivot)->whereIn('permission_id', $permissionIds)->delete();
        }
        DB::table('permissions')->where('module', 'presupuesto')->delete();
        if (Schema::hasTable('pages')) {
            DB::table('pages')->where('key', 'presupuesto')->delete();
        }
        Cache::forget('permissions:all:names');
    }
};
