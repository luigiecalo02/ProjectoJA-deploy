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

        $lugarId = DB::table('pages')->where('key', 'lugares')->value('id');
        if (! $lugarId) {
            return;
        }

        DB::table('pages')->where('key', 'lugares')->update([
            'description' => 'Catálogo de sedes: mapa, terrenos/lotes y cabañas',
            'updated_at' => now(),
        ]);

        DB::table('pages')->whereIn('key', ['terrenos', 'cabanas'])->update([
            'is_active' => false,
            'updated_at' => now(),
        ]);

        $catalogMap = [
            'terrenos.view' => 'lugares.view',
            'terrenos.create' => 'lugares.create',
            'terrenos.update' => 'lugares.update',
            'terrenos.delete' => 'lugares.delete',
            'cabanas.create' => 'lugares.create',
            'cabanas.update' => 'lugares.update',
            'cabanas.delete' => 'lugares.delete',
        ];

        $pivot = Schema::hasTable('permission_role')
            ? 'permission_role'
            : (Schema::hasTable('role_permission') ? 'role_permission' : null);

        $grantToRoles = function (string $fromName, string $toName) use ($pivot): void {
            if (! $pivot) {
                return;
            }
            $fromId = DB::table('permissions')->where('name', $fromName)->value('id');
            $toId = DB::table('permissions')->where('name', $toName)->value('id');
            if (! $fromId || ! $toId) {
                return;
            }
            $roleIds = DB::table($pivot)->where('permission_id', $fromId)->pluck('role_id');
            foreach ($roleIds as $roleId) {
                $exists = DB::table($pivot)
                    ->where('role_id', $roleId)
                    ->where('permission_id', $toId)
                    ->exists();
                if (! $exists) {
                    DB::table($pivot)->insert([
                        'role_id' => $roleId,
                        'permission_id' => $toId,
                    ]);
                }
            }
        };

        foreach ($catalogMap as $fromName => $toName) {
            $grantToRoles($fromName, $toName);
        }
        foreach (['cabanas.create', 'cabanas.update', 'cabanas.delete'] as $fromName) {
            $grantToRoles($fromName, 'lugares.view');
        }

        $kept = [
            'terrenos.assign' => ['Asignar lotes a clubes', 11],
            'terrenos.override_capacity' => ['Sobreasignar capacidad de lotes', 12],
            'cabanas.assign' => ['Asignar camas', 13],
            'cabanas.self_assign' => ['Elegir cama propia', 14],
        ];

        foreach ($kept as $name => [$displayName, $sortOrder]) {
            DB::table('permissions')->where('name', $name)->update([
                'page_id' => $lugarId,
                'module' => 'lugares',
                'display_name' => $displayName,
                'sort_order' => $sortOrder,
                'updated_at' => now(),
            ]);
        }

        foreach ([
            'view' => 'Ver lugares, terrenos y cabañas',
            'create' => 'Crear lugares, terrenos y cabañas',
            'update' => 'Actualizar lugares, terrenos y cabañas',
            'delete' => 'Eliminar lugares, terrenos y cabañas',
        ] as $action => $displayName) {
            DB::table('permissions')->where('name', "lugares.{$action}")->update([
                'display_name' => $displayName,
                'updated_at' => now(),
            ]);
        }

        Cache::forget('permissions:all:names');
    }

    public function down(): void
    {
        if (! Schema::hasTable('pages') || ! Schema::hasTable('permissions')) {
            return;
        }

        $terrenosId = DB::table('pages')->where('key', 'terrenos')->value('id');
        $cabanasId = DB::table('pages')->where('key', 'cabanas')->value('id');

        DB::table('pages')->whereIn('key', ['terrenos', 'cabanas'])->update([
            'is_active' => true,
            'updated_at' => now(),
        ]);

        if ($terrenosId) {
            DB::table('permissions')->whereIn('name', ['terrenos.assign', 'terrenos.override_capacity'])->update([
                'page_id' => $terrenosId,
                'module' => 'terrenos',
                'updated_at' => now(),
            ]);
        }

        if ($cabanasId) {
            DB::table('permissions')->whereIn('name', ['cabanas.assign', 'cabanas.self_assign'])->update([
                'page_id' => $cabanasId,
                'module' => 'cabanas',
                'updated_at' => now(),
            ]);
        }

        Cache::forget('permissions:all:names');
    }
};
