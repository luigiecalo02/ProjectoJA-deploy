<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('evento_inscripcion', function (Blueprint $table) {
            $table->foreignId('evento_lote_id')
                ->nullable()
                ->constrained('eventos_lotes')
                ->nullOnDelete();
            $table->foreignId('evento_cabana_id')
                ->nullable()
                ->constrained('evento_cabanas')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('evento_inscripcion', function (Blueprint $table) {
            $table->dropConstrainedForeignId('evento_cabana_id');
            $table->dropConstrainedForeignId('evento_lote_id');
        });
    }
};
