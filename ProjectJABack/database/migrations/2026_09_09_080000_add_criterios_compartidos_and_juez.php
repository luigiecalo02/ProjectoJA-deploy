<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('events', function (Blueprint $table) {
            $table->boolean('criterios_compartidos')->default(true)->after('puntaje_por_participar');
        });

        Schema::table('evento_criterio', function (Blueprint $table) {
            $table->foreignId('juez_id')
                ->nullable()
                ->after('orden')
                ->constrained('users')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('evento_criterio', function (Blueprint $table) {
            $table->dropConstrainedForeignId('juez_id');
        });

        Schema::table('events', function (Blueprint $table) {
            $table->dropColumn('criterios_compartidos');
        });
    }
};
