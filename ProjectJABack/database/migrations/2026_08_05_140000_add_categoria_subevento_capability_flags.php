<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('categoria_subevento', function (Blueprint $table) {
            $table->boolean('maneja_puntos')->default(true)->after('estado');
            $table->boolean('maneja_fecha_inicio')->default(false)->after('maneja_puntos');
            $table->boolean('maneja_fecha_fin')->default(false)->after('maneja_fecha_inicio');
        });
    }

    public function down(): void
    {
        Schema::table('categoria_subevento', function (Blueprint $table) {
            $table->dropColumn(['maneja_puntos', 'maneja_fecha_inicio', 'maneja_fecha_fin']);
        });
    }
};
