<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('evento_inscripcion_movimiento', function (Blueprint $table) {
            $table->id();
            $table->foreignId('inscripcion_id')->constrained('evento_inscripcion')->cascadeOnDelete();
            $table->unsignedInteger('numero');
            $table->string('tipo', 32);
            $table->decimal('total_anterior', 12, 2)->default(0);
            $table->decimal('total_nuevo', 12, 2)->default(0);
            $table->decimal('valor_diferencia', 12, 2)->default(0);
            $table->json('snapshot');
            $table->json('cambios');
            $table->foreignId('creado_por')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['inscripcion_id', 'numero'], 'eim_inscripcion_numero_unique');
        });

        Schema::table('evento_inscripcion_comprobante', function (Blueprint $table) {
            $table->foreignId('movimiento_id')
                ->nullable()
                ->after('inscripcion_id')
                ->constrained('evento_inscripcion_movimiento')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('evento_inscripcion_comprobante', function (Blueprint $table) {
            $table->dropConstrainedForeignId('movimiento_id');
        });
        Schema::dropIfExists('evento_inscripcion_movimiento');
    }
};
