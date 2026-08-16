<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('evento_evidencia', function (Blueprint $table) {
            $table->dropForeign(['inscripcion_id']);
        });

        DB::statement('ALTER TABLE evento_evidencia MODIFY inscripcion_id BIGINT UNSIGNED NULL');

        Schema::table('evento_evidencia', function (Blueprint $table) {
            $table->foreign('inscripcion_id')
                ->references('id')
                ->on('evento_inscripcion')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('evento_evidencia', function (Blueprint $table) {
            $table->dropForeign(['inscripcion_id']);
        });

        DB::statement('ALTER TABLE evento_evidencia MODIFY inscripcion_id BIGINT UNSIGNED NOT NULL');

        Schema::table('evento_evidencia', function (Blueprint $table) {
            $table->foreign('inscripcion_id')
                ->references('id')
                ->on('evento_inscripcion')
                ->cascadeOnDelete();
        });
    }
};
