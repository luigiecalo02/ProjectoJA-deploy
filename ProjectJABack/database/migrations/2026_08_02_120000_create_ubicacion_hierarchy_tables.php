<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pais', function (Blueprint $table) {
            $table->id();
            $table->string('nombre')->unique();
            $table->timestamps();
        });

        Schema::create('departamento', function (Blueprint $table) {
            $table->id();
            $table->foreignId('pais_id')->constrained('pais')->restrictOnDelete();
            $table->string('nombre');
            $table->timestamps();

            $table->unique(['pais_id', 'nombre']);
            $table->index('pais_id');
        });

        Schema::create('ciudad', function (Blueprint $table) {
            $table->id();
            $table->foreignId('departamento_id')->constrained('departamento')->restrictOnDelete();
            $table->string('nombre');
            $table->timestamps();

            $table->unique(['departamento_id', 'nombre']);
            $table->index('departamento_id');
        });

        Schema::table('organizacion', function (Blueprint $table) {
            $table->foreignId('pais_id')
                ->nullable()
                ->after('tipo_organizacion_id')
                ->constrained('pais')
                ->restrictOnDelete();
            $table->foreignId('departamento_id')
                ->nullable()
                ->after('pais_id')
                ->constrained('departamento')
                ->restrictOnDelete();
            $table->foreignId('ciudad_id')
                ->nullable()
                ->after('departamento_id')
                ->constrained('ciudad')
                ->restrictOnDelete();

            $table->index(['pais_id', 'departamento_id', 'ciudad_id']);
        });
    }

    public function down(): void
    {
        Schema::table('organizacion', function (Blueprint $table) {
            $table->dropConstrainedForeignId('ciudad_id');
            $table->dropConstrainedForeignId('departamento_id');
            $table->dropConstrainedForeignId('pais_id');
        });

        Schema::dropIfExists('ciudad');
        Schema::dropIfExists('departamento');
        Schema::dropIfExists('pais');
    }
};
