<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('asignaciones_cama', 'snapshot_cama_codigo')) {
            Schema::table('asignaciones_cama', function (Blueprint $table) {
                $table->string('snapshot_cabana_nombre')->nullable();
                $table->string('snapshot_piso_nombre')->nullable();
                $table->string('snapshot_cuarto_nombre')->nullable();
                $table->string('snapshot_cama_codigo', 50)->nullable();
                $table->decimal('snapshot_precio', 12, 2)->nullable();
            });
        }

        if (Schema::getConnection()->getDriverName() === 'sqlite') {
            return;
        }

        Schema::table('asignaciones_cama', function (Blueprint $table) {
            $table->dropForeign(['evento_cabana_cama_id']);
        });
        Schema::table('asignaciones_cama', function (Blueprint $table) {
            $table->unsignedBigInteger('evento_cabana_cama_id')->nullable()->change();
            $table->foreign('evento_cabana_cama_id')
                ->references('id')
                ->on('evento_cabana_camas')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        if (Schema::hasColumn('asignaciones_cama', 'snapshot_cama_codigo')) {
            Schema::table('asignaciones_cama', function (Blueprint $table) {
                $table->dropColumn([
                    'snapshot_cabana_nombre',
                    'snapshot_piso_nombre',
                    'snapshot_cuarto_nombre',
                    'snapshot_cama_codigo',
                    'snapshot_precio',
                ]);
            });
        }
    }
};
