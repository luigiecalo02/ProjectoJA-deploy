<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::dropIfExists('usuario_organizacion_cargo');
        Schema::dropIfExists('usuario_organizacion');
    }

    public function down(): void
    {
        Schema::create('usuario_organizacion', function (Blueprint $table) {
            $table->id();
            $table->foreignId('usuario_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('organizacion_id')->constrained('organizacion')->cascadeOnDelete();
            $table->foreignId('rol_id')->constrained('roles')->restrictOnDelete();
            $table->date('fecha_inicio')->nullable();
            $table->date('fecha_fin')->nullable();
            $table->boolean('activo')->default(true)->index();
            $table->timestamps();

            $table->unique(['usuario_id', 'organizacion_id', 'rol_id'], 'usuario_org_rol_unique');
            $table->index(['organizacion_id', 'activo']);
        });

        Schema::create('usuario_organizacion_cargo', function (Blueprint $table) {
            $table->id();
            $table->foreignId('usuario_organizacion_id')
                ->constrained('usuario_organizacion')
                ->cascadeOnDelete();
            $table->foreignId('cargo_id')->constrained('cargo')->restrictOnDelete();
            $table->timestamps();

            $table->unique(['usuario_organizacion_id', 'cargo_id'], 'usuario_org_cargo_unique');
        });
    }
};
