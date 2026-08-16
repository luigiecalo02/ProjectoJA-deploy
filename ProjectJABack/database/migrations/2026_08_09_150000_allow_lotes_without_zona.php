<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('lotes_terreno', function (Blueprint $table) {
            $table->foreignId('terreno_id')->nullable()->after('id')->constrained('terrenos')->cascadeOnDelete();
        });

        DB::statement('
            UPDATE lotes_terreno lt
            INNER JOIN zonas_terreno zt ON zt.id = lt.zona_terreno_id
            SET lt.terreno_id = zt.terreno_id
            WHERE lt.terreno_id IS NULL
        ');

        Schema::table('lotes_terreno', function (Blueprint $table) {
            $table->dropForeign(['zona_terreno_id']);
            $table->dropUnique(['zona_terreno_id', 'codigo']);
        });

        DB::statement('ALTER TABLE lotes_terreno MODIFY zona_terreno_id BIGINT UNSIGNED NULL');

        Schema::table('lotes_terreno', function (Blueprint $table) {
            $table->foreign('zona_terreno_id')->references('id')->on('zonas_terreno')->nullOnDelete();
            $table->unique(['terreno_id', 'codigo']);
        });

        Schema::table('eventos_lotes', function (Blueprint $table) {
            $table->foreignId('evento_terreno_id')->nullable()->after('id')->constrained('eventos_terrenos')->cascadeOnDelete();
        });

        DB::statement('
            UPDATE eventos_lotes el
            INNER JOIN eventos_zonas ez ON ez.id = el.evento_zona_id
            SET el.evento_terreno_id = ez.evento_terreno_id
            WHERE el.evento_terreno_id IS NULL
        ');

        Schema::table('eventos_lotes', function (Blueprint $table) {
            $table->dropForeign(['evento_zona_id']);
        });

        DB::statement('ALTER TABLE eventos_lotes MODIFY evento_zona_id BIGINT UNSIGNED NULL');

        Schema::table('eventos_lotes', function (Blueprint $table) {
            $table->foreign('evento_zona_id')->references('id')->on('eventos_zonas')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('eventos_lotes', function (Blueprint $table) {
            $table->dropForeign(['evento_terreno_id']);
            $table->dropColumn('evento_terreno_id');
        });

        Schema::table('lotes_terreno', function (Blueprint $table) {
            $table->dropUnique(['terreno_id', 'codigo']);
            $table->dropForeign(['terreno_id']);
            $table->dropColumn('terreno_id');
        });
    }
};
