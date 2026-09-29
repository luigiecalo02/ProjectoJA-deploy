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
        $asistencia = $this->upsertPage($now, [
            'key' => 'asistencia',
            'name' => 'Asistencia',
            'route_name' => 'asistencia',
            'icon' => 'pi pi-check-square',
            'sort_order' => 43,
            'front' => 'clubes',
            'description' => 'Registro de asistencia de integrantes a eventos del club',
        ], [
            ['action' => 'view', 'display_name' => 'Ver asistencia', 'sort_order' => 1],
            ['action' => 'update', 'display_name' => 'Registrar asistencia', 'sort_order' => 2],
        ]);

        $abonos = $this->upsertPage($now, [
            'key' => 'abonos',
            'name' => 'Abonos',
            'route_name' => 'abonos',
            'icon' => 'pi pi-wallet',
            'sort_order' => 44,
            'front' => 'clubes',
            'description' => 'Registro de lo recogido en actividades económicas',
        ], [
            ['action' => 'view', 'display_name' => 'Ver abonos', 'sort_order' => 1],
            ['action' => 'update', 'display_name' => 'Registrar abonos', 'sort_order' => 2],
        ]);

        $pivot = Schema::hasTable('permission_role')
            ? 'permission_role'
            : (Schema::hasTable('role_permission') ? 'role_permission' : null);

        if (! $pivot) {
            Cache::forget('permissions:all:names');

            return;
        }

        $adminRoleIds = DB::table('roles')
            ->where(function ($query) {
                $query->where('name', 'admin')->orWhere('is_super', true);
            })
            ->pluck('id')
            ->map(fn ($id) => (int) $id)
            ->all();

        $this->grantPermissions($pivot, $adminRoleIds, array_values(array_merge($asistencia, $abonos)));
        $this->grantToRoles($pivot, ['director', 'subdirector', 'secretario'], array_values(array_merge($asistencia, $abonos)));
        $this->grantToRoles($pivot, ['tesorero'], array_values($abonos));
        $this->grantToRoles($pivot, ['miembro'], array_filter([$asistencia['view'] ?? null]));

        Cache::forget('permissions:all:names');
    }

    public function down(): void
    {
        if (! Schema::hasTable('permissions')) {
            return;
        }

        $keys = ['asistencia', 'abonos'];
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

        Cache::forget('permissions:all:names');
    }

    /**
     * @param  array{key: string, name: string, route_name: string, icon: string, sort_order: int, front: string, description: string}  $page
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

        if (Schema::hasColumn('pages', 'front')) {
            $payload['front'] = $page['front'];
        }

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
     * @param  list<string>  $roleNames
     * @param  list<int>  $permissionIds
     */
    private function grantToRoles(string $pivot, array $roleNames, array $permissionIds): void
    {
        $roleIds = DB::table('roles')
            ->whereIn('name', $roleNames)
            ->pluck('id')
            ->map(fn ($id) => (int) $id)
            ->all();

        $this->grantPermissions($pivot, $roleIds, $permissionIds);
    }

    /**
     * @param  list<int>  $roleIds
     * @param  list<int>  $permissionIds
     */
    private function grantPermissions(string $pivot, array $roleIds, array $permissionIds): void
    {
        foreach ($roleIds as $roleId) {
            foreach ($permissionIds as $permissionId) {
                if (! $permissionId) {
                    continue;
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
            }
        }
    }
};
