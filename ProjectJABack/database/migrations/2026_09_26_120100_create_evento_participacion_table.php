<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('evento_participacion')) {
            return;
        }

        Schema::create('evento_participacion', function (Blueprint $table) {
            $table->id();
            $table->foreignId('evento_id')->constrained('events')->cascadeOnDelete();
            $table->unsignedBigInteger('organizacion_id');
            $table->unsignedBigInteger('persona_id');
            $table->boolean('participa')->default(false);
            $table->timestamps();

            $table->unique(['evento_id', 'organizacion_id', 'persona_id'], 'evento_participacion_unique');
            $table->index(['organizacion_id', 'participa']);
            $table->foreign('organizacion_id')->references('id')->on('organizacion')->cascadeOnDelete();
            $table->foreign('persona_id')->references('id')->on('personas')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('evento_participacion');
    }
};
