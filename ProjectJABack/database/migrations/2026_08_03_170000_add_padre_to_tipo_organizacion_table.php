<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tipo_organizacion', function (Blueprint $table) {
            $table->foreignId('tipo_organizacion_padre_id')
                ->nullable()
                ->after('id')
                ->constrained('tipo_organizacion')
                ->nullOnDelete();

            $table->index('tipo_organizacion_padre_id');
        });
    }

    public function down(): void
    {
        Schema::table('tipo_organizacion', function (Blueprint $table) {
            $table->dropConstrainedForeignId('tipo_organizacion_padre_id');
        });
    }
};
