<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('events') || Schema::hasColumn('events', 'ganancia_eclesiastica_id')) {
            return;
        }

        Schema::table('events', function (Blueprint $table) {
            $table->unsignedBigInteger('ganancia_eclesiastica_id')->nullable()->after('tipo_evento_id');
            $table->foreign('ganancia_eclesiastica_id')
                ->references('id')
                ->on('clubes_ganancia_eclesiasticas')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('events') || ! Schema::hasColumn('events', 'ganancia_eclesiastica_id')) {
            return;
        }

        Schema::table('events', function (Blueprint $table) {
            $table->dropForeign(['ganancia_eclesiastica_id']);
            $table->dropColumn('ganancia_eclesiastica_id');
        });
    }
};
