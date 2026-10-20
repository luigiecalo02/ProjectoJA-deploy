<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('clubes_caja_cuentas')) {
            Schema::create('clubes_caja_cuentas', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('organizacion_id')->index();
                $table->string('nombre', 120);
                $table->string('tipo', 40)->default('caja');
                $table->unsignedSmallInteger('orden')->default(0);
                $table->boolean('activa')->default(true);
                $table->timestamps();

                $table->unique(['organizacion_id', 'nombre']);
            });
        }

        if (! Schema::hasTable('clubes_caja_movimientos')) {
            Schema::create('clubes_caja_movimientos', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('organizacion_id')->index();
                $table->unsignedBigInteger('cuenta_id')->index();
                $table->string('tipo', 20);
                $table->string('origen', 40)->default('manual');
                $table->decimal('monto', 14, 2);
                $table->string('concepto', 255);
                $table->unsignedBigInteger('evento_id')->nullable()->index();
                $table->string('referencia_tipo', 60)->nullable();
                $table->unsignedBigInteger('referencia_id')->nullable();
                $table->unsignedBigInteger('registrado_por')->nullable();
                $table->timestamp('occurred_at')->index();
                $table->timestamps();

                $table->index(['organizacion_id', 'occurred_at']);
                $table->index(['referencia_tipo', 'referencia_id']);
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('clubes_caja_movimientos');
        Schema::dropIfExists('clubes_caja_cuentas');
    }
};
