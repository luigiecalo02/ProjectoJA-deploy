<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Varios jueces y supervisores por evento; rol supervisor (solo ver puntajes).
 */
return new class extends Migration
{
    public function up(): void
    {
        $now = now();

        if (Schema::hasTable('pages') && Schema::hasTable('permissions')) {
            $eventsPageId = DB::table('pages')->where('key', 'events')->value('id');

            $viewScoresId = DB::table('permissions')->where('name', 'events.view_scores')->value('id');
            if ($viewScoresId) {
                DB::table('permissions')->where('id', $viewScoresId)->update([
                    'display_name' => 'Ver puntajes',
                    'module' => 'events',
                    'page_id' => $eventsPageId,
                    'action' => 'view_scores',
                    'sort_order' => 6,
                    'updated_at' => $now,
                ]);
                $viewScoresId = (int) $viewScoresId;
            } else {
                $viewScoresId = (int) DB::table('permissions')->insertGetId([
                    'name' => 'events.view_scores',
                    'display_name' => 'Ver puntajes',
                    'module' => 'events',
                    'page_id' => $eventsPageId,
                    'action' => 'view_scores',
                    'sort_order' => 6,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
            }

            $supervisorId = DB::table('roles')->where('name', 'supervisor')->value('id');
            if ($supervisorId) {
                DB::table('roles')->where('id', $supervisorId)->update([
                    'display_name' => 'Supervisor',
                    'description' => 'Consulta puntajes de eventos sin poder editar ni calificar',
                    'is_system' => true,
                    'is_super' => false,
                    'estado' => true,
                    'updated_at' => $now,
                ]);
                $supervisorId = (int) $supervisorId;
            } else {
                $supervisorId = (int) DB::table('roles')->insertGetId([
                    'name' => 'supervisor',
                    'display_name' => 'Supervisor',
                    'description' => 'Consulta puntajes de eventos sin poder editar ni calificar',
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

            if ($pivot) {
                $supervisorPermIds = DB::table('permissions')
                    ->whereIn('name', ['dashboard.view', 'events.view', 'events.view_scores'])
                    ->pluck('id')
                    ->map(fn ($id) => (int) $id)
                    ->all();

                foreach ($supervisorPermIds as $permissionId) {
                    $exists = DB::table($pivot)
                        ->where('role_id', $supervisorId)
                        ->where('permission_id', $permissionId)
                        ->exists();
                    if (! $exists) {
                        DB::table($pivot)->insert([
                            'role_id' => $supervisorId,
                            'permission_id' => $permissionId,
                        ]);
                    }
                }

                $adminRoleId = DB::table('roles')->where('name', 'admin')->value('id');
                if ($adminRoleId) {
                    $exists = DB::table($pivot)
                        ->where('role_id', $adminRoleId)
                        ->where('permission_id', $viewScoresId)
                        ->exists();
                    if (! $exists) {
                        DB::table($pivot)->insert([
                            'role_id' => (int) $adminRoleId,
                            'permission_id' => $viewScoresId,
                        ]);
                    }
                }

                // Los jueces también pueden ver puntajes.
                $juezRoleId = DB::table('roles')->where('name', 'juez')->value('id');
                if ($juezRoleId) {
                    $exists = DB::table($pivot)
                        ->where('role_id', $juezRoleId)
                        ->where('permission_id', $viewScoresId)
                        ->exists();
                    if (! $exists) {
                        DB::table($pivot)->insert([
                            'role_id' => (int) $juezRoleId,
                            'permission_id' => $viewScoresId,
                        ]);
                    }
                }
            }
        }

        if (Schema::hasTable('events') && ! Schema::hasTable('evento_juez')) {
            Schema::create('evento_juez', function (Blueprint $table) {
                $table->id();
                $table->foreignId('evento_id')->constrained('events')->cascadeOnDelete();
                $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
                $table->timestamps();
                $table->unique(['evento_id', 'user_id']);
            });
        }

        if (Schema::hasTable('events') && ! Schema::hasTable('evento_supervisor')) {
            Schema::create('evento_supervisor', function (Blueprint $table) {
                $table->id();
                $table->foreignId('evento_id')->constrained('events')->cascadeOnDelete();
                $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
                $table->timestamps();
                $table->unique(['evento_id', 'user_id']);
            });
        }

        // Migrar juez_user_id existente a la tabla pivote.
        if (Schema::hasTable('events')
            && Schema::hasColumn('events', 'juez_user_id')
            && Schema::hasTable('evento_juez')
        ) {
            $rows = DB::table('events')
                ->whereNotNull('juez_user_id')
                ->get(['id', 'juez_user_id']);

            foreach ($rows as $row) {
                $exists = DB::table('evento_juez')
                    ->where('evento_id', $row->id)
                    ->where('user_id', $row->juez_user_id)
                    ->exists();
                if (! $exists) {
                    DB::table('evento_juez')->insert([
                        'evento_id' => $row->id,
                        'user_id' => $row->juez_user_id,
                        'created_at' => $now,
                        'updated_at' => $now,
                    ]);
                }
            }

            Schema::table('events', function (Blueprint $table) {
                $table->dropConstrainedForeignId('juez_user_id');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('events') && ! Schema::hasColumn('events', 'juez_user_id')) {
            Schema::table('events', function (Blueprint $table) {
                $table->foreignId('juez_user_id')
                    ->nullable()
                    ->after('created_by')
                    ->constrained('users')
                    ->nullOnDelete();
            });

            if (Schema::hasTable('evento_juez')) {
                $firsts = DB::table('evento_juez')
                    ->select('evento_id', DB::raw('MIN(user_id) as user_id'))
                    ->groupBy('evento_id')
                    ->get();
                foreach ($firsts as $row) {
                    DB::table('events')->where('id', $row->evento_id)->update([
                        'juez_user_id' => $row->user_id,
                    ]);
                }
            }
        }

        Schema::dropIfExists('evento_supervisor');
        Schema::dropIfExists('evento_juez');

        if (Schema::hasTable('permissions')) {
            $viewScoresId = DB::table('permissions')->where('name', 'events.view_scores')->value('id');
            $pivot = Schema::hasTable('permission_role')
                ? 'permission_role'
                : (Schema::hasTable('role_permission') ? 'role_permission' : null);
            if ($viewScoresId && $pivot) {
                DB::table($pivot)->where('permission_id', $viewScoresId)->delete();
            }
            DB::table('permissions')->where('name', 'events.view_scores')->delete();
        }

        if (Schema::hasTable('roles')) {
            $supervisorId = DB::table('roles')->where('name', 'supervisor')->value('id');
            $pivot = Schema::hasTable('permission_role')
                ? 'permission_role'
                : (Schema::hasTable('role_permission') ? 'role_permission' : null);
            if ($supervisorId && $pivot) {
                DB::table($pivot)->where('role_id', $supervisorId)->delete();
            }
            DB::table('roles')->where('name', 'supervisor')->delete();
        }
    }
};
