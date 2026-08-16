<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('events', function (Blueprint $table) {
            $table->boolean('maneja_fecha_fin')->default(false)->after('nivel_conjunto');
            $table->boolean('maneja_penalizaciones')->default(false)->after('maneja_fecha_fin');
            $table->text('reglas_penalizacion')->nullable()->after('maneja_penalizaciones');
        });
    }

    public function down(): void
    {
        Schema::table('events', function (Blueprint $table) {
            $table->dropColumn(['maneja_fecha_fin', 'maneja_penalizaciones', 'reglas_penalizacion']);
        });
    }
};
