<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('events', function (Blueprint $table) {
            $table->boolean('requiere_evidencia')->default(false)->after('puntos_penalizacion');
            $table->json('tipos_evidencia')->nullable()->after('requiere_evidencia');
        });
    }

    public function down(): void
    {
        Schema::table('events', function (Blueprint $table) {
            $table->dropColumn(['requiere_evidencia', 'tipos_evidencia']);
        });
    }
};
