<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('events', function (Blueprint $table) {
            if (! Schema::hasColumn('events', 'requiere_puesto_entrega')) {
                $table->boolean('requiere_puesto_entrega')->default(false)->after('tiempo_estimado_minutos');
            }
            if (! Schema::hasColumn('events', 'requiere_tiempo_entrega')) {
                $table->boolean('requiere_tiempo_entrega')->default(false)->after('requiere_puesto_entrega');
            }
            if (! Schema::hasColumn('events', 'resultado_esperado')) {
                $table->unsignedInteger('resultado_esperado')->nullable()->after('requiere_tiempo_entrega');
            }
        });

        Schema::table('evento_calificacion', function (Blueprint $table) {
            if (! Schema::hasColumn('evento_calificacion', 'puesto_entrega')) {
                $table->string('puesto_entrega', 80)->nullable()->after('observaciones');
            }
            if (! Schema::hasColumn('evento_calificacion', 'tiempo_entrega')) {
                $table->time('tiempo_entrega')->nullable()->after('puesto_entrega');
            }
            if (! Schema::hasColumn('evento_calificacion', 'resultado_obtenido')) {
                $table->unsignedInteger('resultado_obtenido')->nullable()->after('tiempo_entrega');
            }
        });
    }

    public function down(): void
    {
        Schema::table('events', function (Blueprint $table) {
            foreach (['requiere_puesto_entrega', 'requiere_tiempo_entrega', 'resultado_esperado'] as $column) {
                if (Schema::hasColumn('events', $column)) {
                    $table->dropColumn($column);
                }
            }
        });

        Schema::table('evento_calificacion', function (Blueprint $table) {
            foreach (['puesto_entrega', 'tiempo_entrega', 'resultado_obtenido'] as $column) {
                if (Schema::hasColumn('evento_calificacion', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
