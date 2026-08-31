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

        Schema::table('evento_evidencia', function (Blueprint $table) {
            $table->unsignedBigInteger('inscripcion_id')->nullable()->change();
        });

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

        Schema::table('evento_evidencia', function (Blueprint $table) {
            $table->unsignedBigInteger('inscripcion_id')->nullable(false)->change();
        });

        Schema::table('evento_evidencia', function (Blueprint $table) {
            $table->foreign('inscripcion_id')
                ->references('id')
                ->on('evento_inscripcion')
                ->cascadeOnDelete();
        });
    }
};
