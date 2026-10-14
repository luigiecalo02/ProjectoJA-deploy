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

        if (! Schema::hasColumn('evento_participacion_abono', 'estado')) {
            Schema::table('evento_participacion_abono', function (Blueprint $table) {
                $table->string('estado', 20)->default('aprobado');
            });
        }

        if (! Schema::hasColumn('evento_participacion_abono', 'comprobante_path')) {
            Schema::table('evento_participacion_abono', function (Blueprint $table) {
                $table->string('comprobante_path')->nullable();
            });
        }
    }

    public function down(): void
    {
        if (! Schema::hasTable('evento_participacion_abono')) {
            return;
        }

        if (Schema::hasColumn('evento_participacion_abono', 'comprobante_path')) {
            Schema::table('evento_participacion_abono', function (Blueprint $table) {
                $table->dropColumn('comprobante_path');
            });
        }

        if (Schema::hasColumn('evento_participacion_abono', 'estado')) {
            Schema::table('evento_participacion_abono', function (Blueprint $table) {
                $table->dropColumn('estado');
            });
        }
    }
};
