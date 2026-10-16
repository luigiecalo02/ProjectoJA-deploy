<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('evento_producto_servicio') || Schema::hasColumn('evento_producto_servicio', 'inversion')) {
            return;
        }

        Schema::table('evento_producto_servicio', function (Blueprint $table) {
            $table->decimal('inversion', 14, 2)->default(0)->after('cantidad_proyectada');
        });
    }

    public function down(): void
    {
        if (Schema::hasTable('evento_producto_servicio') && Schema::hasColumn('evento_producto_servicio', 'inversion')) {
            Schema::table('evento_producto_servicio', function (Blueprint $table) {
                $table->dropColumn('inversion');
            });
        }
    }
};
