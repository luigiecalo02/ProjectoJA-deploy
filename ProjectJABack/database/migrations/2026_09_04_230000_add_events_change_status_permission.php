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

        $now = now();
        $eventsPageId = DB::table('pages')->where('key', 'events')->value('id');
        $name = 'events.change_status';

        $permissionId = DB::table('permissions')->where('name', $name)->value('id');
        $payload = [
            'display_name' => 'Cambiar estado de eventos',
            'module' => 'events',
            'page_id' => $eventsPageId,
            'action' => 'change_status',
            'sort_order' => 7,
            'updated_at' => $now,
        ];

        if ($permissionId) {
            DB::table('permissions')->where('id', $permissionId)->update($payload);
            $permissionId = (int) $permissionId;
        } else {
            $permissionId = (int) DB::table('permissions')->insertGetId([
                'name' => $name,
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
                    $query->where('name', 'admin')->orWhere('is_super', true);
                })
                ->pluck('id');

            foreach ($roleIds as $roleId) {
                $exists = DB::table($pivot)
                    ->where('role_id', $roleId)
                    ->where('permission_id', $permissionId)
                    ->exists();
                if (! $exists) {
                    DB::table($pivot)->insert([
                        'role_id' => (int) $roleId,
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

        $permissionId = DB::table('permissions')->where('name', 'events.change_status')->value('id');
        $pivot = Schema::hasTable('permission_role')
            ? 'permission_role'
            : (Schema::hasTable('role_permission') ? 'role_permission' : null);

        if ($permissionId && $pivot) {
            DB::table($pivot)->where('permission_id', $permissionId)->delete();
        }

        if ($permissionId) {
            DB::table('permissions')->where('id', $permissionId)->delete();
        }

        Cache::forget('permissions:all:names');
    }
};
