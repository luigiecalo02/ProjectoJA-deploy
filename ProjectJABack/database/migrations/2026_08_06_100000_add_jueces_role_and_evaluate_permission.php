<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Crea el permiso events.evaluate y el rol de sistema "jueces".
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('roles') || ! Schema::hasTable('permissions')) {
            return;
        }

        $now = now();

        $eventsPageId = DB::table('pages')->where('key', 'events')->value('id');

        $evaluateId = DB::table('permissions')->where('name', 'events.evaluate')->value('id');
        if ($evaluateId) {
            DB::table('permissions')->where('id', $evaluateId)->update([
                'display_name' => 'Evaluar eventos',
                'module' => 'events',
                'page_id' => $eventsPageId,
                'action' => 'evaluate',
                'sort_order' => 5,
                'updated_at' => $now,
            ]);
            $evaluateId = (int) $evaluateId;
        } else {
            $evaluateId = (int) DB::table('permissions')->insertGetId([
                'name' => 'events.evaluate',
                'display_name' => 'Evaluar eventos',
                'module' => 'events',
                'page_id' => $eventsPageId,
                'action' => 'evaluate',
                'sort_order' => 5,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }

        $viewId = DB::table('permissions')->where('name', 'events.view')->value('id');
        $dashboardViewId = DB::table('permissions')->where('name', 'dashboard.view')->value('id');

        $juecesId = DB::table('roles')->where('name', 'jueces')->value('id');
        if ($juecesId) {
            DB::table('roles')->where('id', $juecesId)->update([
                'display_name' => 'Jueces',
                'description' => 'Evalúa y califica eventos y subeventos',
                'is_system' => true,
                'is_super' => false,
                'estado' => true,
                'updated_at' => $now,
            ]);
            $juecesId = (int) $juecesId;
        } else {
            $juecesId = (int) DB::table('roles')->insertGetId([
                'name' => 'jueces',
                'display_name' => 'Jueces',
                'description' => 'Evalúa y califica eventos y subeventos',
                'is_system' => true,
                'is_super' => false,
                'estado' => true,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }

        $pivot = Schema::hasTable('permission_role')
            ? 'permission_role'
            : (Schema::hasTable('role_permission') ? 'role_permission' : null);

        if (! $pivot) {
            return;
        }

        $juecesPermissionIds = array_values(array_filter([
            $dashboardViewId ? (int) $dashboardViewId : null,
            $viewId ? (int) $viewId : null,
            $evaluateId,
        ]));

        foreach ($juecesPermissionIds as $permissionId) {
            $exists = DB::table($pivot)
                ->where('role_id', $juecesId)
                ->where('permission_id', $permissionId)
                ->exists();
            if (! $exists) {
                DB::table($pivot)->insert([
                    'role_id' => $juecesId,
                    'permission_id' => $permissionId,
                ]);
            }
        }

        $adminRoleId = DB::table('roles')->where('name', 'admin')->value('id');
        if ($adminRoleId) {
            $exists = DB::table($pivot)
                ->where('role_id', $adminRoleId)
                ->where('permission_id', $evaluateId)
                ->exists();
            if (! $exists) {
                DB::table($pivot)->insert([
                    'role_id' => (int) $adminRoleId,
                    'permission_id' => $evaluateId,
                ]);
            }
        }
    }

    public function down(): void
    {
        if (! Schema::hasTable('permissions')) {
            return;
        }

        $evaluateId = DB::table('permissions')->where('name', 'events.evaluate')->value('id');
        $juecesId = DB::table('roles')->where('name', 'jueces')->value('id');

        $pivot = Schema::hasTable('permission_role')
            ? 'permission_role'
            : (Schema::hasTable('role_permission') ? 'role_permission' : null);

        if ($pivot && $evaluateId) {
            DB::table($pivot)->where('permission_id', $evaluateId)->delete();
        }

        if ($pivot && $juecesId) {
            DB::table($pivot)->where('role_id', $juecesId)->delete();
        }

        if ($evaluateId) {
            DB::table('permissions')->where('id', $evaluateId)->delete();
        }

        if ($juecesId) {
            DB::table('roles')->where('id', $juecesId)->delete();
        }
    }
};
