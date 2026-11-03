<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('evento_participacion_abono')) {
            return;
        }

        Schema::table('evento_participacion_abono', function (Blueprint $table) {
            $table->dropForeign(['evento_id']);
        });

        $driver = Schema::getConnection()->getDriverName();
        if ($driver === 'mysql') {
            DB::statement('ALTER TABLE evento_participacion_abono MODIFY evento_id BIGINT UNSIGNED NULL');
        } else {
            Schema::table('evento_participacion_abono', function (Blueprint $table) {
                $table->unsignedBigInteger('evento_id')->nullable()->change();
            });
        }

        Schema::table('evento_participacion_abono', function (Blueprint $table) {
            $table->foreign('evento_id')
                ->references('id')
                ->on('events')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('evento_participacion_abono')) {
            return;
        }

        Schema::table('evento_participacion_abono', function (Blueprint $table) {
            $table->dropForeign(['evento_id']);
        });

        DB::table('evento_participacion_abono')
            ->whereNull('evento_id')
            ->delete();

        $driver = Schema::getConnection()->getDriverName();
        if ($driver === 'mysql') {
            DB::statement('ALTER TABLE evento_participacion_abono MODIFY evento_id BIGINT UNSIGNED NOT NULL');
        } else {
            Schema::table('evento_participacion_abono', function (Blueprint $table) {
                $table->unsignedBigInteger('evento_id')->nullable(false)->change();
            });
        }

        Schema::table('evento_participacion_abono', function (Blueprint $table) {
            $table->foreign('evento_id')
                ->references('id')
                ->on('events')
                ->cascadeOnDelete();
        });
    }
};
