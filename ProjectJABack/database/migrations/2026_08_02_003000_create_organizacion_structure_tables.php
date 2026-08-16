<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('google_id')->nullable()->unique()->after('provider_id');
            $table->string('facebook_id')->nullable()->unique()->after('google_id');
        });

        Schema::table('roles', function (Blueprint $table) {
            $table->boolean('estado')->default(true)->index()->after('description');
        });

        Schema::create('tipo_organizacion', function (Blueprint $table) {
            $table->id();
            $table->string('nombre')->unique();
            $table->text('descripcion')->nullable();
            $table->boolean('estado')->default(true)->index();
            $table->timestamps();
        });

        Schema::create('organizacion', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organizacion_padre_id')
                ->nullable()
                ->constrained('organizacion')
                ->nullOnDelete();
            $table->foreignId('tipo_organizacion_id')
                ->constrained('tipo_organizacion')
                ->restrictOnDelete();
            $table->string('nombre');
            $table->string('codigo', 50)->nullable()->unique();
            $table->string('direccion')->nullable();
            $table->string('telefono', 40)->nullable();
            $table->string('correo')->nullable();
            $table->boolean('estado')->default(true)->index();
            $table->timestamp('fecha_creacion')->useCurrent();
            $table->timestamp('fecha_actualizacion')->useCurrent()->useCurrentOnUpdate();

            $table->index(['tipo_organizacion_id', 'estado']);
            $table->index('organizacion_padre_id');
        });

        Schema::create('cargo', function (Blueprint $table) {
            $table->id();
            $table->string('nombre')->unique();
            $table->text('descripcion')->nullable();
            $table->timestamps();
        });

        Schema::create('persona_organizacion', function (Blueprint $table) {
            $table->id();
            $table->foreignId('persona_id')->constrained('personas')->cascadeOnDelete();
            $table->foreignId('organizacion_id')->constrained('organizacion')->cascadeOnDelete();
            $table->date('fecha_ingreso')->nullable();
            $table->date('fecha_retiro')->nullable();
            $table->boolean('estado')->default(true)->index();
            $table->timestamps();

            $table->unique(['persona_id', 'organizacion_id']);
        });

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
            $table->date('fecha_inicio')->nullable();
            $table->date('fecha_fin')->nullable();
            $table->timestamps();

            $table->unique(['usuario_organizacion_id', 'cargo_id'], 'usuario_org_cargo_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('usuario_organizacion_cargo');
        Schema::dropIfExists('usuario_organizacion');
        Schema::dropIfExists('persona_organizacion');
        Schema::dropIfExists('cargo');
        Schema::dropIfExists('organizacion');
        Schema::dropIfExists('tipo_organizacion');

        Schema::table('roles', function (Blueprint $table) {
            $table->dropColumn('estado');
        });

        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['google_id', 'facebook_id']);
        });
    }
};
