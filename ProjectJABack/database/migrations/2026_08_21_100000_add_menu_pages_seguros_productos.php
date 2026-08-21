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
        $pivot = Schema::hasTable('permission_role')
            ? 'permission_role'
            : (Schema::hasTable('role_permission') ? 'role_permission' : null);

        $seguros = $this->upsertPage($now, [
            'key' => 'seguros_consulta',
            'name' => 'Consultar seguro',
            'route_name' => 'segurosConsulta',
            'icon' => 'pi pi-shield',
            'sort_order' => 41,
            'description' => 'Consulta de vigencia de seguros de personas',
        ], [
            ['action' => 'view', 'display_name' => 'Ver consultar seguro', 'sort_order' => 1],
        ]);

        $productos = $this->upsertPage($now, [
            'key' => 'productos_servicios',
            'name' => 'Servicios',
            'route_name' => 'productosServicios',
            'icon' => 'pi pi-box',
            'sort_order' => 42,
            'description' => 'Catálogo de productos y servicios para eventos',
        ], [
            ['action' => 'view', 'display_name' => 'Ver servicios', 'sort_order' => 1],
            ['action' => 'create', 'display_name' => 'Crear servicios', 'sort_order' => 2],
            ['action' => 'update', 'display_name' => 'Actualizar servicios', 'sort_order' => 3],
        ]);

        if (! $pivot) {
            return;
        }

        $adminRoleId = DB::table('roles')->where('name', 'admin')->value('id');
        $superRoleIds = DB::table('roles')->where('is_super', true)->pluck('id')->all();
        $allNewIds = array_values(array_merge($seguros, $productos));

        if ($adminRoleId) {
            $this->grantPermissions($pivot, [(int) $adminRoleId], $allNewIds);
        }

        $eventsViewId = DB::table('permissions')->where('name', 'events.view')->value('id');
        if ($eventsViewId) {
            $roleIds = $this->roleIdsWithPermission($pivot, (int) $eventsViewId, $superRoleIds);
            $this->grantPermissions($pivot, $roleIds, array_filter([
                $seguros['view'] ?? null,
                $productos['view'] ?? null,
            ]));
        }

        $eventsUpdateId = DB::table('permissions')->where('name', 'events.update')->value('id');
        if ($eventsUpdateId) {
            $roleIds = $this->roleIdsWithPermission($pivot, (int) $eventsUpdateId, $superRoleIds);
            $this->grantPermissions($pivot, $roleIds, array_filter([
                $productos['create'] ?? null,
                $productos['update'] ?? null,
            ]));
        }

        \Illuminate\Support\Facades\Cache::forget('permissions:all:names');
    }

    public function down(): void
    {
        if (! Schema::hasTable('permissions')) {
            return;
        }

        $keys = ['seguros_consulta', 'productos_servicios'];
        $permissionIds = DB::table('permissions')->whereIn('module', $keys)->pluck('id');

        $pivot = Schema::hasTable('permission_role')
            ? 'permission_role'
            : (Schema::hasTable('role_permission') ? 'role_permission' : null);

        if ($permissionIds->isNotEmpty() && $pivot) {
            DB::table($pivot)->whereIn('permission_id', $permissionIds)->delete();
        }

        DB::table('permissions')->whereIn('module', $keys)->delete();

        if (Schema::hasTable('pages')) {
            DB::table('pages')->whereIn('key', $keys)->delete();
        }
    }

    /**
     * @param  array{key: string, name: string, route_name: string, icon: string, sort_order: int, description: string}  $page
     * @param  list<array{action: string, display_name: string, sort_order: int}>  $actions
     * @return array<string, int>
     */
    private function upsertPage(mixed $now, array $page, array $actions): array
    {
        $pageId = DB::table('pages')->where('key', $page['key'])->value('id');
        $payload = [
            'name' => $page['name'],
            'route_name' => $page['route_name'],
            'icon' => $page['icon'],
            'sort_order' => $page['sort_order'],
            'is_active' => true,
            'description' => $page['description'],
            'updated_at' => $now,
        ];

        if (! $pageId) {
            $pageId = DB::table('pages')->insertGetId([
                'key' => $page['key'],
                ...$payload,
                'created_at' => $now,
            ]);
        } else {
            DB::table('pages')->where('id', $pageId)->update($payload);
        }

        $permissionIds = [];
        foreach ($actions as $perm) {
            $name = "{$page['key']}.{$perm['action']}";
            $existingId = DB::table('permissions')->where('name', $name)->value('id');
            $permPayload = [
                'display_name' => $perm['display_name'],
                'module' => $page['key'],
                'page_id' => $pageId,
                'action' => $perm['action'],
                'sort_order' => $perm['sort_order'],
                'updated_at' => $now,
            ];

            if ($existingId) {
                DB::table('permissions')->where('id', $existingId)->update($permPayload);
                $permissionIds[$perm['action']] = (int) $existingId;
            } else {
                $permissionIds[$perm['action']] = (int) DB::table('permissions')->insertGetId([
                    'name' => $name,
                    ...$permPayload,
                    'created_at' => $now,
                ]);
            }
        }

        return $permissionIds;
    }

    /**
     * @param  list<int|string>  $superRoleIds
     * @return list<int>
     */
    private function roleIdsWithPermission(string $pivot, int $permissionId, array $superRoleIds): array
    {
        $super = array_map('intval', $superRoleIds);

        return DB::table($pivot)
            ->where('permission_id', $permissionId)
            ->pluck('role_id')
            ->map(fn ($id) => (int) $id)
            ->reject(fn (int $id) => in_array($id, $super, true))
            ->unique()
            ->values()
            ->all();
    }

    /**
     * @param  list<int>  $roleIds
     * @param  list<int>  $permissionIds
     */
    private function grantPermissions(string $pivot, array $roleIds, array $permissionIds): void
    {
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
};
