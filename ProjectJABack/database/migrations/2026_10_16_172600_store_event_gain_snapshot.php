<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('evento_producto_servicio')) {
            Schema::table('evento_producto_servicio', function (Blueprint $table) {
                if (! Schema::hasColumn('evento_producto_servicio', 'total')) {
                    $table->decimal('total', 14, 2)->default(0)->after('inversion');
                }
                if (! Schema::hasColumn('evento_producto_servicio', 'ganancia_unidad')) {
                    $table->decimal('ganancia_unidad', 14, 2)->default(0)->after('total');
                }
            });
        }

        if (Schema::hasTable('events')) {
            Schema::table('events', function (Blueprint $table) {
                if (! Schema::hasColumn('events', 'ganancia_proyeccion')) {
                    $table->decimal('ganancia_proyeccion', 14, 2)->default(0)->after('capital');
                }
                if (! Schema::hasColumn('events', 'ganancia_proyectada')) {
                    $table->decimal('ganancia_proyectada', 14, 2)->default(0)->after('ganancia_proyeccion');
                }
                if (! Schema::hasColumn('events', 'ganancia_ofrenda')) {
                    $table->decimal('ganancia_ofrenda', 14, 2)->default(0)->after('ganancia_proyectada');
                }
                if (! Schema::hasColumn('events', 'ganancia_diezmo')) {
                    $table->decimal('ganancia_diezmo', 14, 2)->default(0)->after('ganancia_ofrenda');
                }
                if (! Schema::hasColumn('events', 'ganancia_resto')) {
                    $table->decimal('ganancia_resto', 14, 2)->default(0)->after('ganancia_diezmo');
                }
                if (! Schema::hasColumn('events', 'ganancia_participantes')) {
                    $table->decimal('ganancia_participantes', 14, 2)->default(0)->after('ganancia_resto');
                }
                if (! Schema::hasColumn('events', 'ganancia_club')) {
                    $table->decimal('ganancia_club', 14, 2)->default(0)->after('ganancia_participantes');
                }
                if (! Schema::hasColumn('events', 'ganancia_extras')) {
                    $table->decimal('ganancia_extras', 14, 2)->default(0)->after('ganancia_club');
                }
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('evento_producto_servicio')) {
            Schema::table('evento_producto_servicio', function (Blueprint $table) {
                if (Schema::hasColumn('evento_producto_servicio', 'ganancia_unidad')) {
                    $table->dropColumn('ganancia_unidad');
                }
                if (Schema::hasColumn('evento_producto_servicio', 'total')) {
                    $table->dropColumn('total');
                }
            });
        }

        if (Schema::hasTable('events')) {
            Schema::table('events', function (Blueprint $table) {
                foreach ([
                    'ganancia_extras',
                    'ganancia_club',
                    'ganancia_participantes',
                    'ganancia_resto',
                    'ganancia_diezmo',
                    'ganancia_ofrenda',
                    'ganancia_proyectada',
                    'ganancia_proyeccion',
                ] as $column) {
                    if (Schema::hasColumn('events', $column)) {
                        $table->dropColumn($column);
                    }
                }
            });
        }
    }
};
