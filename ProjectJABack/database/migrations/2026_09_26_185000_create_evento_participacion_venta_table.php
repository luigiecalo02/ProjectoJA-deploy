<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('evento_participacion_venta')) {
            return;
        }

        Schema::create('evento_participacion_venta', function (Blueprint $table) {
            $table->id();
            $table->foreignId('evento_participacion_id')
                ->constrained('evento_participacion')
                ->cascadeOnDelete();
            $table->unsignedBigInteger('producto_servicio_id');
            $table->unsignedInteger('cantidad')->default(0);
            $table->timestamps();

            $table->unique(['evento_participacion_id', 'producto_servicio_id'], 'evento_part_venta_unique');
            $table->foreign('producto_servicio_id')->references('id')->on('productos_servicios')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('evento_participacion_venta');
    }
};
