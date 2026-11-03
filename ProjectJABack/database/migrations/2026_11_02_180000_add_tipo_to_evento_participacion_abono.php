<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('evento_participacion_abono')) {
            return;
        }
        if (Schema::hasColumn('evento_participacion_abono', 'tipo')) {
            return;
        }

        Schema::table('evento_participacion_abono', function (Blueprint $table) {
            $table->string('tipo', 20)->default('actividad')->after('producto_servicio_id');
            $table->index(['organizacion_id', 'tipo'], 'abono_org_tipo_idx');
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('evento_participacion_abono')) {
            return;
        }
        if (! Schema::hasColumn('evento_participacion_abono', 'tipo')) {
            return;
        }

        Schema::table('evento_participacion_abono', function (Blueprint $table) {
            $table->dropIndex('abono_org_tipo_idx');
            $table->dropColumn('tipo');
        });
    }
};
