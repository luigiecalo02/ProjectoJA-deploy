<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('clubes_evento_presupuestos')) {
            Schema::create('clubes_evento_presupuestos', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('evento_id')->unique();
                $table->unsignedBigInteger('organizacion_id');
                $table->unsignedInteger('acompanantes_count')->default(0);
                $table->timestamps();

                $table->index('organizacion_id');
                $table->foreign('evento_id')->references('id')->on('events')->cascadeOnDelete();
                $table->foreign('organizacion_id')->references('id')->on('organizacion')->cascadeOnDelete();
            });
        }

        if (! Schema::hasTable('clubes_evento_presupuesto_items')) {
            Schema::create('clubes_evento_presupuesto_items', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('presupuesto_id');
                $table->string('concepto');
                $table->string('tipo', 20);
                $table->decimal('monto', 12, 2);
                $table->string('destinatario', 20);
                $table->unsignedInteger('orden')->default(0);
                $table->timestamps();

                $table->index('presupuesto_id');
                $table->foreign('presupuesto_id')
                    ->references('id')
                    ->on('clubes_evento_presupuestos')
                    ->cascadeOnDelete();
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('clubes_evento_presupuesto_items');
        Schema::dropIfExists('clubes_evento_presupuestos');
    }
};
