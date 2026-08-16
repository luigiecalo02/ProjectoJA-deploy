<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('events', function (Blueprint $table) {
            $table->dateTime('fecha_limite_inscripcion')->nullable()->after('permite_inscripcion_iglesia');
            $table->decimal('puntos_inscripcion_a_tiempo', 12, 2)->nullable()->after('fecha_limite_inscripcion');
            $table->decimal('puntos_inscripcion_fuera_tiempo', 12, 2)->nullable()->after('puntos_inscripcion_a_tiempo');
        });
    }

    public function down(): void
    {
        Schema::table('events', function (Blueprint $table) {
            $table->dropColumn([
                'fecha_limite_inscripcion',
                'puntos_inscripcion_a_tiempo',
                'puntos_inscripcion_fuera_tiempo',
            ]);
        });
    }
};
