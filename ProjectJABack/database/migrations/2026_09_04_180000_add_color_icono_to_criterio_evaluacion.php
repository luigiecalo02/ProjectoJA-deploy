<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('criterio_evaluacion')) {
            return;
        }

        Schema::table('criterio_evaluacion', function (Blueprint $table) {
            if (! Schema::hasColumn('criterio_evaluacion', 'color')) {
                $table->string('color', 32)->nullable()->after('descripcion');
            }
            if (! Schema::hasColumn('criterio_evaluacion', 'icono')) {
                $table->string('icono', 64)->nullable()->after('color');
            }
        });
    }

    public function down(): void
    {
        Schema::table('criterio_evaluacion', function (Blueprint $table) {
            foreach (['icono', 'color'] as $column) {
                if (Schema::hasColumn('criterio_evaluacion', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
