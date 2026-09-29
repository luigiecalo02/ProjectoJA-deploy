<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('evento_participacion_abono')) {
            return;
        }

        Schema::create('evento_participacion_abono', function (Blueprint $table) {
            $table->id();
            $table->foreignId('evento_id')->constrained('events')->cascadeOnDelete();
            $table->unsignedBigInteger('organizacion_id');
            $table->foreignId('persona_id')->constrained('personas')->cascadeOnDelete();
            $table->decimal('monto', 12, 2);
            $table->string('nota', 255)->nullable();
            $table->foreignId('registrado_por')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['organizacion_id', 'persona_id'], 'abono_org_persona_idx');
            $table->index(['organizacion_id', 'evento_id'], 'abono_org_evento_idx');
            $table->foreign('organizacion_id')->references('id')->on('organizacion')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('evento_participacion_abono');
    }
};
