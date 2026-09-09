<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('events', function (Blueprint $table) {
            $table->boolean('premia_puestos')->default(false)->after('reglas_penalizacion');
            $table->decimal('puntos_puesto_1', 12, 2)->nullable()->after('premia_puestos');
            $table->decimal('puntos_puesto_2', 12, 2)->nullable()->after('puntos_puesto_1');
            $table->decimal('puntos_puesto_3', 12, 2)->nullable()->after('puntos_puesto_2');
        });
    }

    public function down(): void
    {
        Schema::table('events', function (Blueprint $table) {
            $table->dropColumn([
                'premia_puestos',
                'puntos_puesto_1',
                'puntos_puesto_2',
                'puntos_puesto_3',
            ]);
        });
    }
};
