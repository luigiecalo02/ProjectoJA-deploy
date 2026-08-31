<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('evento_alojamiento_cupos', function (Blueprint $table) {
            $table->id();
            $table->foreignId('evento_id')->constrained('events')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->unsignedInteger('cupos');
            $table->string('estado', 24)->default('abierto');
            $table->timestamp('cerrado_at')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->unique(['evento_id', 'user_id']);
            $table->index(['evento_id', 'estado']);
        });

        Schema::table('asignaciones_cama', function (Blueprint $table) {
            $table->foreignId('evento_alojamiento_cupo_id')->nullable()->after('asignado_por')
                ->constrained('evento_alojamiento_cupos')->nullOnDelete();
        });

        Schema::table('asignaciones_cama', function (Blueprint $table) {
            $table->dropForeign(['evento_servicio_reserva_id']);
        });

        Schema::table('asignaciones_cama', function (Blueprint $table) {
            $table->unsignedBigInteger('evento_servicio_reserva_id')->nullable()->change();
        });

        Schema::table('asignaciones_cama', function (Blueprint $table) {
            $table->foreign('evento_servicio_reserva_id')
                ->references('id')
                ->on('evento_servicio_reserva')
                ->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('asignaciones_cama', function (Blueprint $table) {
            $table->dropForeign(['evento_alojamiento_cupo_id']);
            $table->dropColumn('evento_alojamiento_cupo_id');
            $table->dropForeign(['evento_servicio_reserva_id']);
        });

        Schema::table('asignaciones_cama', function (Blueprint $table) {
            $table->unsignedBigInteger('evento_servicio_reserva_id')->nullable(false)->change();
        });

        Schema::table('asignaciones_cama', function (Blueprint $table) {
            $table->foreign('evento_servicio_reserva_id')
                ->references('id')
                ->on('evento_servicio_reserva')
                ->cascadeOnDelete();
        });

        Schema::dropIfExists('evento_alojamiento_cupos');
    }
};
