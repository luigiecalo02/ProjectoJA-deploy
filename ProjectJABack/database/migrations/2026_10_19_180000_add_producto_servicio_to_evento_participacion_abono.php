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

        if (! Schema::hasColumn('evento_participacion_abono', 'producto_servicio_id')) {
            Schema::table('evento_participacion_abono', function (Blueprint $table) {
                $table->foreignId('producto_servicio_id')
                    ->nullable()
                    ->after('persona_id')
                    ->constrained('productos_servicios')
                    ->nullOnDelete();
            });
        }
    }

    public function down(): void
    {
        if (! Schema::hasTable('evento_participacion_abono')) {
            return;
        }

        if (Schema::hasColumn('evento_participacion_abono', 'producto_servicio_id')) {
            Schema::table('evento_participacion_abono', function (Blueprint $table) {
                $table->dropConstrainedForeignId('producto_servicio_id');
            });
        }
    }
};
