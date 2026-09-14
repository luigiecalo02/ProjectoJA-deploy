<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('evento_asistencias')) {
            return;
        }

        Schema::create('evento_asistencias', function (Blueprint $table) {
            $table->id();
            $table->foreignId('evento_id')->constrained('events')->cascadeOnDelete();
            $table->unsignedBigInteger('organizacion_id');
            $table->unsignedBigInteger('persona_id');
            $table->string('estado', 20);
            $table->string('notas', 255)->nullable();
            $table->unsignedBigInteger('registrado_por')->nullable();
            $table->timestamps();

            $table->unique(['evento_id', 'organizacion_id', 'persona_id'], 'evento_asistencias_unique');
            $table->index(['organizacion_id', 'estado']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('evento_asistencias');
    }
};
