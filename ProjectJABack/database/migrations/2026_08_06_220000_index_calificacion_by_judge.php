<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Índice para lookup por juez (sin unique estricto por NULLs de inscripción).
        Schema::table('evento_calificacion', function (Blueprint $table) {
            $table->index(
                ['evento_id', 'organizacion_id', 'calificado_por'],
                'evento_calif_evento_org_juez_idx'
            );
        });

        // Filas legacy de subeventos sin juez: no se tocan; el agregador las trata como un aporte.
    }

    public function down(): void
    {
        Schema::table('evento_calificacion', function (Blueprint $table) {
            $table->dropIndex('evento_calif_evento_org_juez_idx');
        });
    }
};
