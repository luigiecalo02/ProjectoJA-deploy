<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('evento_calificacion', function (Blueprint $table) {
            $table->foreignId('calificado_por')
                ->nullable()
                ->after('observaciones')
                ->constrained('users')
                ->nullOnDelete();
        });

        Schema::create('evento_calificacion_detalle', function (Blueprint $table) {
            $table->id();
            $table->foreignId('calificacion_id')->constrained('evento_calificacion')->cascadeOnDelete();
            $table->foreignId('criterio_evaluacion_id')->constrained('criterio_evaluacion')->cascadeOnDelete();
            $table->decimal('puntos', 12, 2)->default(0);
            $table->timestamps();

            $table->unique(['calificacion_id', 'criterio_evaluacion_id'], 'evento_calif_detalle_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('evento_calificacion_detalle');

        Schema::table('evento_calificacion', function (Blueprint $table) {
            $table->dropConstrainedForeignId('calificado_por');
        });
    }
};
