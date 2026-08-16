<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('evento_calificacion_obs_director', function (Blueprint $table) {
            $table->id();
            $table->foreignId('evento_id')->constrained('events')->cascadeOnDelete();
            $table->foreignId('organizacion_id')->constrained('organizacion')->cascadeOnDelete();
            $table->text('observaciones');
            $table->foreignId('creado_por')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['evento_id', 'organizacion_id'], 'evento_calif_obs_dir_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('evento_calificacion_obs_director');
    }
};
