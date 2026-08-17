<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('app_settings', function (Blueprint $table) {
            $table->id();
            $table->string('login_hero_path')->nullable();
            $table->string('pattern_light_path')->nullable();
            $table->string('pattern_dark_path')->nullable();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        DB::table('app_settings')->insert([
            'id' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        if (! Schema::hasTable('pages') || ! Schema::hasTable('permissions')) {
            return;
        }

        $now = now();
        $pageId = DB::table('pages')->where('key', 'settings')->value('id');
        if (! $pageId) {
            $pageId = DB::table('pages')->insertGetId([
                'key' => 'settings',
                'name' => 'Apariencia',
                'route_name' => 'settings.brand',
                'icon' => 'pi pi-palette',
                'sort_order' => 35,
                'is_active' => true,
                'description' => 'Imágenes de inicio de sesión y fondos de la plataforma',
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }

        $actions = [
            ['action' => 'view', 'display_name' => 'Ver apariencia', 'sort_order' => 1],
            ['action' => 'update', 'display_name' => 'Actualizar apariencia', 'sort_order' => 2],
        ];

        $permissionIds = [];
        foreach ($actions as $perm) {
            $name = "settings.{$perm['action']}";
            $existingId = DB::table('permissions')->where('name', $name)->value('id');
            if ($existingId) {
                DB::table('permissions')->where('id', $existingId)->update([
                    'display_name' => $perm['display_name'],
                    'module' => 'settings',
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
                    'module' => 'settings',
                    'page_id' => $pageId,
                    'action' => $perm['action'],
                    'sort_order' => $perm['sort_order'],
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
            }
        }

        $adminRoleId = DB::table('roles')->where('name', 'admin')->value('id');
        $pivot = Schema::hasTable('permission_role') ? 'permission_role' : null;
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
        if (Schema::hasTable('permissions')) {
            $permissionIds = DB::table('permissions')->where('module', 'settings')->pluck('id');
            if ($permissionIds->isNotEmpty() && Schema::hasTable('permission_role')) {
                DB::table('permission_role')->whereIn('permission_id', $permissionIds)->delete();
            }
            DB::table('permissions')->where('module', 'settings')->delete();
        }

        if (Schema::hasTable('pages')) {
            DB::table('pages')->where('key', 'settings')->delete();
        }

        Schema::dropIfExists('app_settings');
    }
};
