<?php

use Illuminate\Database\Migrations\Migration;
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

        $pageId = DB::table('pages')->where('key', 'integrantes')->value('id');
        if (! $pageId) {
            $pageId = DB::table('pages')->insertGetId([
                'key' => 'integrantes',
                'name' => 'Integrantes',
                'route_name' => 'integrantes',
                'icon' => 'pi pi-users',
                'sort_order' => 56,
                'is_active' => true,
                'description' => 'Personas asociadas a clubes (solo organizaciones tipo Club)',
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        } else {
            DB::table('pages')->where('id', $pageId)->update([
                'name' => 'Integrantes',
                'route_name' => 'integrantes',
                'icon' => 'pi pi-users',
                'sort_order' => 56,
                'is_active' => true,
                'description' => 'Personas asociadas a clubes (solo organizaciones tipo Club)',
                'updated_at' => $now,
            ]);
        }

        DB::table('pages')->where('key', 'personas')->update([
            'description' => 'Registro general de personas (cualquier organización del alcance)',
            'updated_at' => $now,
        ]);

        $actions = [
            ['action' => 'view', 'display_name' => 'Ver integrantes', 'sort_order' => 1],
            ['action' => 'create', 'display_name' => 'Crear integrantes', 'sort_order' => 2],
            ['action' => 'update', 'display_name' => 'Actualizar integrantes', 'sort_order' => 3],
            ['action' => 'delete', 'display_name' => 'Eliminar integrantes', 'sort_order' => 4],
        ];

        $permissionIds = [];
        foreach ($actions as $perm) {
            $name = "integrantes.{$perm['action']}";
            $existingId = DB::table('permissions')->where('name', $name)->value('id');
            if ($existingId) {
                DB::table('permissions')->where('id', $existingId)->update([
                    'display_name' => $perm['display_name'],
                    'module' => 'integrantes',
                    'page_id' => $pageId,
                    'action' => $perm['action'],
                    'sort_order' => $perm['sort_order'],
                    'updated_at' => $now,
                ]);
                $permissionIds[] = (int) $existingId;
            } else {
                $permissionIds[] = (int) DB::table('permissions')->insertGetId([
                    'name' => $name,
                    'display_name' => $perm['display_name'],
                    'module' => 'integrantes',
                    'page_id' => $pageId,
                    'action' => $perm['action'],
                    'sort_order' => $perm['sort_order'],
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
            }
        }

        // Admin recibe todos los permisos nuevos.
        $adminRoleId = DB::table('roles')->where('name', 'admin')->value('id');
        $pivot = Schema::hasTable('permission_role')
            ? 'permission_role'
            : (Schema::hasTable('role_permission') ? 'role_permission' : null);

        if ($adminRoleId && $pivot) {
            foreach ($permissionIds as $permissionId) {
                $exists = DB::table($pivot)
                    ->where('role_id', $adminRoleId)
                    ->where('permission_id', $permissionId)
                    ->exists();
                if (! $exists) {
                    DB::table($pivot)->insert([
                        'role_id' => $adminRoleId,
                        'permission_id' => $permissionId,
                    ]);
                }
            }
        }
    }

    public function down(): void
    {
        if (! Schema::hasTable('permissions')) {
            return;
        }

        $permissionIds = DB::table('permissions')
            ->where('module', 'integrantes')
            ->pluck('id');

        $pivot = Schema::hasTable('permission_role')
            ? 'permission_role'
            : (Schema::hasTable('role_permission') ? 'role_permission' : null);

        if ($permissionIds->isNotEmpty() && $pivot) {
            DB::table($pivot)->whereIn('permission_id', $permissionIds)->delete();
        }

        DB::table('permissions')->where('module', 'integrantes')->delete();

        if (Schema::hasTable('pages')) {
            DB::table('pages')->where('key', 'integrantes')->delete();
        }
    }
};
