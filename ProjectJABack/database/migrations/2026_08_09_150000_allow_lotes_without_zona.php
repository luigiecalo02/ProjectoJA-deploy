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
            UPDATE lotes_terreno
            SET terreno_id = (
                SELECT zt.terreno_id FROM zonas_terreno zt WHERE zt.id = lotes_terreno.zona_terreno_id
            )
            WHERE terreno_id IS NULL
        ');

        Schema::table('lotes_terreno', function (Blueprint $table) {
            $table->dropForeign(['zona_terreno_id']);
            $table->dropUnique(['zona_terreno_id', 'codigo']);
        });

        Schema::table('lotes_terreno', function (Blueprint $table) {
            $table->unsignedBigInteger('zona_terreno_id')->nullable()->change();
        });

        Schema::table('lotes_terreno', function (Blueprint $table) {
            $table->foreign('zona_terreno_id')->references('id')->on('zonas_terreno')->nullOnDelete();
            $table->unique(['terreno_id', 'codigo']);
        });

        Schema::table('eventos_lotes', function (Blueprint $table) {
            $table->foreignId('evento_terreno_id')->nullable()->after('id')->constrained('eventos_terrenos')->cascadeOnDelete();
        });

        DB::statement('
            UPDATE eventos_lotes
            SET evento_terreno_id = (
                SELECT ez.evento_terreno_id FROM eventos_zonas ez WHERE ez.id = eventos_lotes.evento_zona_id
            )
            WHERE evento_terreno_id IS NULL
        ');

        Schema::table('eventos_lotes', function (Blueprint $table) {
            $table->dropForeign(['evento_zona_id']);
        });

        Schema::table('eventos_lotes', function (Blueprint $table) {
            $table->unsignedBigInteger('evento_zona_id')->nullable()->change();
        });

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
