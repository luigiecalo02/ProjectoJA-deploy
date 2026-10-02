<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('clubes_ganancia_distribuciones')) {
            Schema::create('clubes_ganancia_distribuciones', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('organizacion_id');
                $table->string('nombre');
                $table->decimal('club', 6, 2);
                $table->decimal('miembros', 6, 2);
                $table->decimal('extras', 6, 2);
                $table->boolean('es_predeterminada')->default(false);
                $table->timestamps();

                $table->index('organizacion_id');
                $table->foreign('organizacion_id')
                    ->references('id')
                    ->on('organizacion')
                    ->cascadeOnDelete();
            });
        }

        if (! Schema::hasTable('clubes_ganancia_eclesiasticas')) {
            Schema::create('clubes_ganancia_eclesiasticas', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('organizacion_id');
                $table->unsignedBigInteger('distribucion_id');
                $table->string('nombre');
                $table->decimal('diezmo', 6, 2);
                $table->decimal('ofrenda', 6, 2);
                $table->boolean('es_predeterminada')->default(false);
                $table->timestamps();

                $table->index('organizacion_id');
                $table->index('distribucion_id');
                $table->foreign('organizacion_id')
                    ->references('id')
                    ->on('organizacion')
                    ->cascadeOnDelete();
                $table->foreign('distribucion_id')
                    ->references('id')
                    ->on('clubes_ganancia_distribuciones')
                    ->restrictOnDelete();
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('clubes_ganancia_eclesiasticas');
        Schema::dropIfExists('clubes_ganancia_distribuciones');
    }
};
