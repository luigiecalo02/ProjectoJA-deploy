<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('criterio_evaluacion', function (Blueprint $table) {
            $table->id();
            $table->string('nombre', 180);
            $table->text('descripcion')->nullable();
            $table->boolean('estado')->default(true)->index();
            $table->unsignedInteger('orden')->default(0);
            $table->timestamps();
        });

        Schema::create('evento_criterio', function (Blueprint $table) {
            $table->id();
            $table->foreignId('evento_id')->constrained('events')->cascadeOnDelete();
            $table->foreignId('criterio_evaluacion_id')->constrained('criterio_evaluacion')->cascadeOnDelete();
            $table->decimal('puntos', 12, 2)->default(0);
            $table->unsignedInteger('orden')->default(0);
            $table->timestamps();

            $table->unique(['evento_id', 'criterio_evaluacion_id'], 'evento_criterio_unique');
            $table->index(['evento_id', 'orden']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('evento_criterio');
        Schema::dropIfExists('criterio_evaluacion');
    }
};
