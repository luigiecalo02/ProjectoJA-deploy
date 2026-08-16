<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cabanas', function (Blueprint $table) {
            $table->id();
            $table->string('nombre');
            $table->text('descripcion')->nullable();
            $table->unsignedInteger('ancho')->default(1000);
            $table->unsignedInteger('alto')->default(700);
            $table->string('estado', 24)->default('activa')->index();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });
        Schema::create('cabana_pisos', function (Blueprint $table) {
            $table->id();
            $table->foreignId('cabana_id')->constrained('cabanas')->cascadeOnDelete();
            $table->string('nombre');
            $table->unsignedInteger('ancho')->default(1000);
            $table->unsignedInteger('alto')->default(650);
            $table->unsignedInteger('orden')->default(0);
            $table->timestamps();
            $table->unique(['cabana_id', 'orden']);
        });
        Schema::create('cabana_cuartos', function (Blueprint $table) {
            $table->id();
            $table->foreignId('cabana_piso_id')->constrained('cabana_pisos')->cascadeOnDelete();
            $table->string('nombre');
            $table->string('codigo', 50)->nullable();
            $table->decimal('x', 10, 2)->default(0);
            $table->decimal('y', 10, 2)->default(0);
            $table->decimal('ancho', 10, 2)->default(200);
            $table->decimal('alto', 10, 2)->default(150);
            $table->string('genero', 8)->default('MIXTO');
            $table->unsignedInteger('capacidad')->default(1);
            $table->unsignedInteger('orden')->default(0);
            $table->timestamps();
            $table->index(['cabana_piso_id', 'genero']);
        });
        Schema::create('cabana_camas', function (Blueprint $table) {
            $table->id();
            $table->foreignId('cabana_cuarto_id')->constrained('cabana_cuartos')->cascadeOnDelete();
            $table->string('codigo', 50);
            $table->string('nombre')->nullable();
            $table->unsignedInteger('capacidad')->default(1);
            $table->decimal('x', 10, 2)->default(0);
            $table->decimal('y', 10, 2)->default(0);
            $table->decimal('ancho', 10, 2)->default(80);
            $table->decimal('alto', 10, 2)->default(190);
            $table->decimal('rotacion', 8, 2)->default(0);
            $table->string('estado', 24)->default('disponible');
            $table->unsignedInteger('orden')->default(0);
            $table->timestamps();
            $table->unique(['cabana_cuarto_id', 'codigo']);
            $table->index(['cabana_cuarto_id', 'estado']);
        });
        Schema::create('evento_cabanas', function (Blueprint $table) {
            $table->id();
            $table->foreignId('evento_id')->constrained('events')->cascadeOnDelete();
            $table->foreignId('cabana_id')->nullable()->constrained('cabanas')->nullOnDelete();
            $table->string('nombre');
            $table->text('descripcion')->nullable();
            $table->unsignedInteger('ancho')->default(1000);
            $table->unsignedInteger('alto')->default(700);
            $table->string('estado', 24)->default('activa');
            $table->unsignedInteger('orden')->default(0);
            $table->timestamps();
            $table->unique(['evento_id', 'cabana_id']);
            $table->index(['evento_id', 'estado']);
        });
        Schema::create('evento_cabana_pisos', function (Blueprint $table) {
            $table->id();
            $table->foreignId('evento_cabana_id')->constrained('evento_cabanas')->cascadeOnDelete();
            $table->foreignId('cabana_piso_id')->nullable()->constrained('cabana_pisos')->nullOnDelete();
            $table->string('nombre');
            $table->unsignedInteger('ancho')->default(1000);
            $table->unsignedInteger('alto')->default(650);
            $table->unsignedInteger('orden')->default(0);
            $table->timestamps();
        });
        Schema::create('evento_cabana_cuartos', function (Blueprint $table) {
            $table->id();
            $table->foreignId('evento_cabana_piso_id')->constrained('evento_cabana_pisos')->cascadeOnDelete();
            $table->foreignId('cabana_cuarto_id')->nullable()->constrained('cabana_cuartos')->nullOnDelete();
            $table->string('nombre');
            $table->string('codigo', 50)->nullable();
            $table->decimal('x', 10, 2)->default(0);
            $table->decimal('y', 10, 2)->default(0);
            $table->decimal('ancho', 10, 2)->default(200);
            $table->decimal('alto', 10, 2)->default(150);
            $table->string('genero', 8)->default('MIXTO');
            $table->unsignedInteger('capacidad')->default(1);
            $table->unsignedInteger('orden')->default(0);
            $table->timestamps();
        });
        Schema::create('evento_cabana_camas', function (Blueprint $table) {
            $table->id();
            $table->foreignId('evento_cabana_cuarto_id')->constrained('evento_cabana_cuartos')->cascadeOnDelete();
            $table->foreignId('cabana_cama_id')->nullable()->constrained('cabana_camas')->nullOnDelete();
            $table->string('codigo', 50);
            $table->string('nombre')->nullable();
            $table->unsignedInteger('capacidad')->default(1);
            $table->decimal('x', 10, 2)->default(0);
            $table->decimal('y', 10, 2)->default(0);
            $table->decimal('ancho', 10, 2)->default(80);
            $table->decimal('alto', 10, 2)->default(190);
            $table->decimal('rotacion', 8, 2)->default(0);
            $table->string('estado', 24)->default('disponible');
            $table->unsignedInteger('orden')->default(0);
            $table->timestamps();
            $table->index(['evento_cabana_cuarto_id', 'estado']);
        });
        Schema::create('asignaciones_cama', function (Blueprint $table) {
            $table->id();
            $table->foreignId('evento_id')->constrained('events')->cascadeOnDelete();
            $table->foreignId('evento_cabana_cama_id')->constrained('evento_cabana_camas')->restrictOnDelete();
            $table->foreignId('inscripcion_persona_id')->constrained('evento_inscripcion_persona')->cascadeOnDelete();
            $table->foreignId('evento_servicio_reserva_id')->constrained('evento_servicio_reserva')->cascadeOnDelete();
            $table->string('estado', 24)->default('activa');
            $table->foreignId('asignado_por')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('liberada_at')->nullable();
            $table->timestamps();
            $table->index(['evento_cabana_cama_id', 'estado']);
            $table->index(['evento_id', 'inscripcion_persona_id', 'estado'], 'ac_evento_persona_estado_idx');
            $table->index(['evento_servicio_reserva_id', 'estado']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('asignaciones_cama');
        Schema::dropIfExists('evento_cabana_camas');
        Schema::dropIfExists('evento_cabana_cuartos');
        Schema::dropIfExists('evento_cabana_pisos');
        Schema::dropIfExists('evento_cabanas');
        Schema::dropIfExists('cabana_camas');
        Schema::dropIfExists('cabana_cuartos');
        Schema::dropIfExists('cabana_pisos');
        Schema::dropIfExists('cabanas');
    }
};
