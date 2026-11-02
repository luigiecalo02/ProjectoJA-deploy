<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('organizacion_notificacion_tipos', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organizacion_id')->constrained('organizacion')->cascadeOnDelete();
            $table->string('tipo', 64);
            $table->boolean('activo')->default(true);
            $table->timestamps();

            $table->unique(['organizacion_id', 'tipo'], 'org_notif_tipo_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('organizacion_notificacion_tipos');
    }
};
