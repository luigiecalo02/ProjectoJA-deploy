<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('events') && ! Schema::hasColumn('events', 'capital')) {
            Schema::table('events', function (Blueprint $table) {
                $table->decimal('capital', 14, 2)->default(0);
            });
        }

        if (Schema::hasTable('evento_producto_servicio') && ! Schema::hasColumn('evento_producto_servicio', 'cantidad_proyectada')) {
            Schema::table('evento_producto_servicio', function (Blueprint $table) {
                $table->unsignedInteger('cantidad_proyectada')->default(0);
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('evento_producto_servicio') && Schema::hasColumn('evento_producto_servicio', 'cantidad_proyectada')) {
            Schema::table('evento_producto_servicio', function (Blueprint $table) {
                $table->dropColumn('cantidad_proyectada');
            });
        }

        if (Schema::hasTable('events') && Schema::hasColumn('events', 'capital')) {
            Schema::table('events', function (Blueprint $table) {
                $table->dropColumn('capital');
            });
        }
    }
};
