<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('iconos', function (Blueprint $table) {
            $table->id();
            $table->string('nombre', 120);
            $table->string('slug', 140)->unique();
            $table->string('categoria', 40)->default('eventos');
            $table->json('etiquetas')->nullable();
            $table->string('tipo', 16)->default('prime');
            $table->string('valor', 255);
            $table->unsignedSmallInteger('orden')->default(0);
            $table->boolean('estado')->default(true);
            $table->boolean('es_sistema')->default(false);
            $table->timestamps();

            $table->index(['categoria', 'estado']);
            $table->index('tipo');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('iconos');
    }
};
