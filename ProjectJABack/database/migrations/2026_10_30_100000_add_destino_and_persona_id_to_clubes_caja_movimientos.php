<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('clubes_caja_movimientos')) {
            return;
        }

        Schema::table('clubes_caja_movimientos', function (Blueprint $table) {
            if (! Schema::hasColumn('clubes_caja_movimientos', 'destino')) {
                $table->string('destino', 40)->nullable()->index()->after('origen');
            }
            if (! Schema::hasColumn('clubes_caja_movimientos', 'persona_id')) {
                $table->unsignedBigInteger('persona_id')->nullable()->index()->after('evento_id');
            }
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('clubes_caja_movimientos')) {
            return;
        }

        Schema::table('clubes_caja_movimientos', function (Blueprint $table) {
            if (Schema::hasColumn('clubes_caja_movimientos', 'persona_id')) {
                $table->dropColumn('persona_id');
            }
            if (Schema::hasColumn('clubes_caja_movimientos', 'destino')) {
                $table->dropColumn('destino');
            }
        });
    }
};
