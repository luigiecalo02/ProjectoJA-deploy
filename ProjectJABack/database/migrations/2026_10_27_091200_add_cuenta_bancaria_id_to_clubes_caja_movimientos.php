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
            if (! Schema::hasColumn('clubes_caja_movimientos', 'cuenta_bancaria_id')) {
                $table->unsignedBigInteger('cuenta_bancaria_id')->nullable()->index()->after('cuenta_id');
            }
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('clubes_caja_movimientos')) {
            return;
        }

        Schema::table('clubes_caja_movimientos', function (Blueprint $table) {
            if (Schema::hasColumn('clubes_caja_movimientos', 'cuenta_bancaria_id')) {
                $table->dropColumn('cuenta_bancaria_id');
            }
        });
    }
};
