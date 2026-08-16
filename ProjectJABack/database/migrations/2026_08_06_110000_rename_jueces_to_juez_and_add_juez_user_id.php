<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Renombra rol jueces → juez y permite asignar un juez a cada (sub)evento.
 */
return new class extends Migration
{
    public function up(): void
    {
        $now = now();

        if (Schema::hasTable('roles')) {
            $jueces = DB::table('roles')->where('name', 'jueces')->first();
            $juez = DB::table('roles')->where('name', 'juez')->first();

            if ($jueces && ! $juez) {
                DB::table('roles')->where('id', $jueces->id)->update([
                    'name' => 'juez',
                    'display_name' => 'Juez',
                    'description' => 'Evalúa y califica eventos y subeventos',
                    'is_system' => true,
                    'updated_at' => $now,
                ]);
            } elseif ($jueces && $juez) {
                $pivot = Schema::hasTable('permission_role')
                    ? 'permission_role'
                    : (Schema::hasTable('role_permission') ? 'role_permission' : null);

                if ($pivot) {
                    $permIds = DB::table($pivot)->where('role_id', $jueces->id)->pluck('permission_id');
                    foreach ($permIds as $permissionId) {
                        $exists = DB::table($pivot)
                            ->where('role_id', $juez->id)
                            ->where('permission_id', $permissionId)
                            ->exists();
                        if (! $exists) {
                            DB::table($pivot)->insert([
                                'role_id' => $juez->id,
                                'permission_id' => $permissionId,
                            ]);
                        }
                    }
                    DB::table($pivot)->where('role_id', $jueces->id)->delete();
                }

                if (Schema::hasTable('persona_organizacion_rol')) {
                    DB::table('persona_organizacion_rol')
                        ->where('rol_id', $jueces->id)
                        ->update(['rol_id' => $juez->id]);
                }

                DB::table('roles')->where('id', $jueces->id)->delete();
            } elseif (! $juez) {
                $juezId = DB::table('roles')->insertGetId([
                    'name' => 'juez',
                    'display_name' => 'Juez',
                    'description' => 'Evalúa y califica eventos y subeventos',
                    'is_system' => true,
                    'is_super' => false,
                    'estado' => true,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);

                $pivot = Schema::hasTable('permission_role')
                    ? 'permission_role'
                    : (Schema::hasTable('role_permission') ? 'role_permission' : null);

                if ($pivot) {
                    $permissionIds = DB::table('permissions')
                        ->whereIn('name', ['dashboard.view', 'events.view', 'events.evaluate'])
                        ->pluck('id');
                    foreach ($permissionIds as $permissionId) {
                        DB::table($pivot)->insert([
                            'role_id' => $juezId,
                            'permission_id' => $permissionId,
                        ]);
                    }
                }
            } else {
                DB::table('roles')->where('id', $juez->id)->update([
                    'display_name' => 'Juez',
                    'description' => 'Evalúa y califica eventos y subeventos',
                    'is_system' => true,
                    'updated_at' => $now,
                ]);
            }
        }

        if (Schema::hasTable('events') && ! Schema::hasColumn('events', 'juez_user_id')) {
            Schema::table('events', function (Blueprint $table) {
                $table->foreignId('juez_user_id')
                    ->nullable()
                    ->after('created_by')
                    ->constrained('users')
                    ->nullOnDelete();
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('events') && Schema::hasColumn('events', 'juez_user_id')) {
            Schema::table('events', function (Blueprint $table) {
                $table->dropConstrainedForeignId('juez_user_id');
            });
        }

        if (Schema::hasTable('roles')) {
            DB::table('roles')->where('name', 'juez')->update([
                'name' => 'jueces',
                'display_name' => 'Jueces',
                'updated_at' => now(),
            ]);
        }
    }
};
