<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('users', 'persona_id')) {
            Schema::table('users', function (Blueprint $table) {
                $table->foreignId('persona_id')
                    ->nullable()
                    ->after('id')
                    ->constrained('personas')
                    ->nullOnDelete();
                $table->unique('persona_id');
            });
        }

        // Backfill: mover personas.user_id → users.persona_id
        if (Schema::hasColumn('personas', 'user_id')) {
            $links = DB::table('personas')
                ->whereNotNull('user_id')
                ->get(['id', 'user_id']);

            foreach ($links as $link) {
                DB::table('users')
                    ->where('id', $link->user_id)
                    ->whereNull('persona_id')
                    ->update(['persona_id' => $link->id]);
            }

            // Primero FK, luego índice único (MySQL no permite dropear el unique mientras la FK lo usa)
            Schema::table('personas', function (Blueprint $table) {
                $table->dropForeign(['user_id']);
            });

            Schema::table('personas', function (Blueprint $table) {
                $table->dropUnique(['user_id']);
                $table->dropColumn('user_id');
            });
        }

        if (Schema::hasColumn('persona_organizacion', 'fecha_ingreso')
            && ! Schema::hasColumn('persona_organizacion', 'fecha_inicio')) {
            Schema::table('persona_organizacion', function (Blueprint $table) {
                $table->date('fecha_inicio')->nullable()->after('organizacion_id');
                $table->date('fecha_fin')->nullable()->after('fecha_inicio');
            });

            DB::table('persona_organizacion')->update([
                'fecha_inicio' => DB::raw('fecha_ingreso'),
                'fecha_fin' => DB::raw('fecha_retiro'),
            ]);

            Schema::table('persona_organizacion', function (Blueprint $table) {
                $table->dropColumn(['fecha_ingreso', 'fecha_retiro']);
            });
        }

        if (! Schema::hasTable('persona_organizacion_rol')) {
            Schema::create('persona_organizacion_rol', function (Blueprint $table) {
                $table->id();
                $table->foreignId('persona_organizacion_id')
                    ->constrained('persona_organizacion')
                    ->cascadeOnDelete();
                $table->foreignId('rol_id')
                    ->constrained('roles')
                    ->restrictOnDelete();
                $table->timestamp('created_at')->useCurrent();

                $table->unique(['persona_organizacion_id', 'rol_id'], 'persona_org_rol_unique');
                $table->index('rol_id');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('persona_organizacion_rol');

        if (Schema::hasColumn('persona_organizacion', 'fecha_inicio')
            && ! Schema::hasColumn('persona_organizacion', 'fecha_ingreso')) {
            Schema::table('persona_organizacion', function (Blueprint $table) {
                $table->date('fecha_ingreso')->nullable()->after('organizacion_id');
                $table->date('fecha_retiro')->nullable()->after('fecha_ingreso');
            });

            DB::table('persona_organizacion')->update([
                'fecha_ingreso' => DB::raw('fecha_inicio'),
                'fecha_retiro' => DB::raw('fecha_fin'),
            ]);

            Schema::table('persona_organizacion', function (Blueprint $table) {
                $table->dropColumn(['fecha_inicio', 'fecha_fin']);
            });
        }

        if (! Schema::hasColumn('personas', 'user_id')) {
            Schema::table('personas', function (Blueprint $table) {
                $table->foreignId('user_id')
                    ->nullable()
                    ->constrained('users')
                    ->nullOnDelete();
                $table->unique('user_id');
            });
        }

        if (Schema::hasColumn('users', 'persona_id')) {
            $links = DB::table('users')
                ->whereNotNull('persona_id')
                ->get(['id', 'persona_id']);

            foreach ($links as $link) {
                DB::table('personas')
                    ->where('id', $link->persona_id)
                    ->update(['user_id' => $link->id]);
            }

            Schema::table('users', function (Blueprint $table) {
                $table->dropForeign(['persona_id']);
            });

            Schema::table('users', function (Blueprint $table) {
                $table->dropUnique(['persona_id']);
                $table->dropColumn('persona_id');
            });
        }
    }
};
