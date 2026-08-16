<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('terrenos', function (Blueprint $table) {
            $table->id();
            $table->string('nombre');
            $table->text('descripcion')->nullable();
            $table->decimal('latitud', 10, 7)->nullable();
            $table->decimal('longitud', 10, 7)->nullable();
            $table->unsignedTinyInteger('nivel_zoom')->default(16);
            $table->json('geometria')->nullable();
            $table->decimal('area_total', 14, 2)->nullable();
            $table->decimal('perimetro', 14, 2)->nullable();
            $table->decimal('metros_por_persona', 8, 2)->default(10);
            $table->string('imagen_referencia')->nullable();
            $table->string('estado', 30)->default('activo');
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('zonas_terreno', function (Blueprint $table) {
            $table->id();
            $table->foreignId('terreno_id')->constrained('terrenos')->cascadeOnDelete();
            $table->string('nombre');
            $table->text('descripcion')->nullable();
            $table->json('geometria')->nullable();
            $table->decimal('area', 14, 2)->nullable();
            $table->decimal('perimetro', 14, 2)->nullable();
            $table->string('color', 20)->nullable();
            $table->unsignedInteger('orden')->default(0);
            $table->string('estado', 30)->default('activo');
            $table->timestamps();
        });

        Schema::create('lotes_terreno', function (Blueprint $table) {
            $table->id();
            $table->foreignId('zona_terreno_id')->constrained('zonas_terreno')->cascadeOnDelete();
            $table->string('codigo', 50);
            $table->string('nombre')->nullable();
            $table->text('descripcion')->nullable();
            $table->json('geometria')->nullable();
            $table->decimal('area', 14, 2)->nullable();
            $table->decimal('perimetro', 14, 2)->nullable();
            $table->unsignedInteger('capacidad_calculada')->nullable();
            $table->unsignedInteger('capacidad_maxima')->nullable();
            $table->string('tipo_capacidad', 20)->default('calculada');
            $table->unsignedInteger('orden')->default(0);
            $table->string('estado', 30)->default('disponible');
            $table->timestamps();
            $table->unique(['zona_terreno_id', 'codigo']);
        });

        Schema::create('eventos_terrenos', function (Blueprint $table) {
            $table->id();
            $table->foreignId('evento_id')->constrained('events')->cascadeOnDelete();
            $table->foreignId('terreno_id')->constrained('terrenos')->restrictOnDelete();
            $table->text('descripcion')->nullable();
            $table->string('estado', 30)->default('activo');
            $table->timestamps();
            $table->unique(['evento_id', 'terreno_id']);
        });

        Schema::create('eventos_zonas', function (Blueprint $table) {
            $table->id();
            $table->foreignId('evento_terreno_id')->constrained('eventos_terrenos')->cascadeOnDelete();
            $table->foreignId('zona_terreno_id')->nullable()->constrained('zonas_terreno')->nullOnDelete();
            $table->string('nombre');
            $table->json('geometria')->nullable();
            $table->decimal('area', 14, 2)->nullable();
            $table->decimal('perimetro', 14, 2)->nullable();
            $table->unsignedInteger('capacidad')->nullable();
            $table->string('color', 20)->nullable();
            $table->unsignedInteger('orden')->default(0);
            $table->string('estado', 30)->default('activo');
            $table->timestamps();
        });

        Schema::create('eventos_lotes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('evento_zona_id')->constrained('eventos_zonas')->cascadeOnDelete();
            $table->foreignId('lote_terreno_id')->nullable()->constrained('lotes_terreno')->nullOnDelete();
            $table->string('codigo', 50);
            $table->string('nombre')->nullable();
            $table->json('geometria')->nullable();
            $table->decimal('area', 14, 2)->nullable();
            $table->decimal('perimetro', 14, 2)->nullable();
            $table->unsignedInteger('capacidad_calculada')->nullable();
            $table->unsignedInteger('capacidad_maxima')->nullable();
            $table->string('tipo_capacidad', 20)->default('calculada');
            $table->unsignedInteger('orden')->default(0);
            $table->string('estado', 30)->default('disponible');
            $table->timestamps();
        });

        Schema::create('asignaciones_lotes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('evento_lote_id')->constrained('eventos_lotes')->cascadeOnDelete();
            $table->foreignId('club_id')->constrained('clubes')->restrictOnDelete();
            $table->unsignedInteger('cantidad_personas')->default(0);
            $table->text('observaciones')->nullable();
            $table->string('estado', 30)->default('activa');
            $table->foreignId('asignado_por')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('asignaciones_lotes');
        Schema::dropIfExists('eventos_lotes');
        Schema::dropIfExists('eventos_zonas');
        Schema::dropIfExists('eventos_terrenos');
        Schema::dropIfExists('lotes_terreno');
        Schema::dropIfExists('zonas_terreno');
        Schema::dropIfExists('terrenos');
    }
};
