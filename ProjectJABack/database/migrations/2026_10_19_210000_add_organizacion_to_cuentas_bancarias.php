<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('cuentas_bancarias')) {
            return;
        }

        Schema::table('cuentas_bancarias', function (Blueprint $table) {
            if (! Schema::hasColumn('cuentas_bancarias', 'organizacion_id')) {
                $table->unsignedBigInteger('organizacion_id')->nullable()->index()->after('id');
            }
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('cuentas_bancarias')) {
            return;
        }

        Schema::table('cuentas_bancarias', function (Blueprint $table) {
            if (Schema::hasColumn('cuentas_bancarias', 'organizacion_id')) {
                $table->dropColumn('organizacion_id');
            }
        });
    }
};
