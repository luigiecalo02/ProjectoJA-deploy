<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('personas') || Schema::hasColumn('personas', 'foto')) {
            return;
        }

        Schema::table('personas', function (Blueprint $table) {
            $table->string('foto')->nullable()->after('direccion_actual');
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('personas') || ! Schema::hasColumn('personas', 'foto')) {
            return;
        }

        Schema::table('personas', function (Blueprint $table) {
            $table->dropColumn('foto');
        });
    }
};
