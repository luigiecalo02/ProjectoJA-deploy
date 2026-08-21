<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('events', function (Blueprint $table) {
            if (! Schema::hasColumn('events', 'puntaje_por_participar')) {
                $table->boolean('puntaje_por_participar')->default(false)->after('puntaje_desde_hijos');
            }
        });

        if (Schema::hasColumn('evento_calificacion', 'tiempo_entrega')) {
            Schema::table('evento_calificacion', function (Blueprint $table) {
                $table->dropColumn('tiempo_entrega');
            });
        }

        Schema::table('evento_calificacion', function (Blueprint $table) {
            if (! Schema::hasColumn('evento_calificacion', 'tiempo_entrega')) {
                $table->string('tiempo_entrega', 16)->nullable()->after('puesto_entrega');
            }
        });
    }

    public function down(): void
    {
        Schema::table('events', function (Blueprint $table) {
            if (Schema::hasColumn('events', 'puntaje_por_participar')) {
                $table->dropColumn('puntaje_por_participar');
            }
        });
    }
};
