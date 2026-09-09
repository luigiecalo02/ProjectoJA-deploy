<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('evento_calificacion', function (Blueprint $table) {
            $table->boolean('permite_editar_evidencia')
                ->default(false)
                ->after('calificado_por');
        });
    }

    public function down(): void
    {
        Schema::table('evento_calificacion', function (Blueprint $table) {
            $table->dropColumn('permite_editar_evidencia');
        });
    }
};
