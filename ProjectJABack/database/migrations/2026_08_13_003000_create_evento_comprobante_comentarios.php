<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('evento_inscripcion_comprobante_comentarios', function (Blueprint $table) {
            $table->id();
            $table->foreignId('comprobante_id');
            $table->foreign('comprobante_id', 'eicc_comprobante_fk')
                ->references('id')
                ->on('evento_inscripcion_comprobante')
                ->cascadeOnDelete();
            $table->foreignId('autor_id');
            $table->foreign('autor_id', 'eicc_autor_fk')
                ->references('id')
                ->on('users')
                ->cascadeOnDelete();
            $table->string('autor_tipo', 24);
            $table->text('mensaje');
            $table->timestamps();
            $table->index(['comprobante_id', 'created_at'], 'eicc_comprobante_fecha_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('evento_inscripcion_comprobante_comentarios');
    }
};
