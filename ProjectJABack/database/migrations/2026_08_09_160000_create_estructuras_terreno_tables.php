<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('estructuras_terreno', function (Blueprint $table) {
            $table->id();
            $table->foreignId('terreno_id')->constrained('terrenos')->cascadeOnDelete();
            $table->string('nombre');
            $table->string('tipo', 40)->default('general');
            $table->text('descripcion')->nullable();
            $table->json('geometria')->nullable();
            $table->decimal('area', 14, 2)->nullable();
            $table->decimal('perimetro', 14, 2)->nullable();
            $table->string('color', 20)->nullable();
            $table->unsignedInteger('orden')->default(0);
            $table->string('estado', 30)->default('activo');
            $table->timestamps();
        });

        Schema::create('eventos_estructuras', function (Blueprint $table) {
            $table->id();
            $table->foreignId('evento_terreno_id')->constrained('eventos_terrenos')->cascadeOnDelete();
            $table->foreignId('estructura_terreno_id')->nullable()->constrained('estructuras_terreno')->nullOnDelete();
            $table->string('nombre');
            $table->string('tipo', 40)->default('general');
            $table->json('geometria')->nullable();
            $table->decimal('area', 14, 2)->nullable();
            $table->decimal('perimetro', 14, 2)->nullable();
            $table->string('color', 20)->nullable();
            $table->unsignedInteger('orden')->default(0);
            $table->string('estado', 30)->default('activo');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('eventos_estructuras');
        Schema::dropIfExists('estructuras_terreno');
    }
};
