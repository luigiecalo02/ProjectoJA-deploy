<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('clubes_informes_participantes')) {
            return;
        }

        Schema::table('clubes_informes_participantes', function (Blueprint $table) {
            if (! Schema::hasColumn('clubes_informes_participantes', 'token')) {
                $table->string('token', 64)->nullable()->unique()->after('token_hash');
            }
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('clubes_informes_participantes')) {
            return;
        }

        Schema::table('clubes_informes_participantes', function (Blueprint $table) {
            if (Schema::hasColumn('clubes_informes_participantes', 'token')) {
                $table->dropUnique(['token']);
                $table->dropColumn('token');
            }
        });
    }
};
