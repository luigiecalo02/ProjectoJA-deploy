<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('evento_evidencia', function (Blueprint $table) {
            $table->id();
            $table->foreignId('evento_id')->constrained('events')->cascadeOnDelete();
            $table->foreignId('organizacion_id')->nullable()->constrained('organizacion')->nullOnDelete();
            $table->foreignId('persona_id')->nullable()->constrained('personas')->nullOnDelete();
            $table->foreignId('inscripcion_id')->constrained('evento_inscripcion')->cascadeOnDelete();
            $table->string('tipo', 32);
            $table->string('titulo')->nullable();
            $table->text('descripcion')->nullable();
            $table->string('url', 2048)->nullable();
            $table->foreignId('file_id')->nullable()->constrained('files')->nullOnDelete();
            $table->foreignId('subido_por')->nullable()->constrained('users')->nullOnDelete();
            $table->string('estado', 32)->default('enviada')->index();
            $table->timestamps();

            $table->index(['evento_id', 'organizacion_id']);
            $table->index(['evento_id', 'persona_id']);
            $table->index('inscripcion_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('evento_evidencia');
    }
};
