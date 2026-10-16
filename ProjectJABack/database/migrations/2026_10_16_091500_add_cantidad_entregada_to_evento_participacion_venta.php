<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('evento_participacion_venta')) {
            return;
        }
        if (Schema::hasColumn('evento_participacion_venta', 'cantidad_entregada')) {
            return;
        }

        Schema::table('evento_participacion_venta', function (Blueprint $table) {
            $table->unsignedInteger('cantidad_entregada')->nullable()->after('cantidad');
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('evento_participacion_venta')) {
            return;
        }
        if (! Schema::hasColumn('evento_participacion_venta', 'cantidad_entregada')) {
            return;
        }

        Schema::table('evento_participacion_venta', function (Blueprint $table) {
            $table->dropColumn('cantidad_entregada');
        });
    }
};
