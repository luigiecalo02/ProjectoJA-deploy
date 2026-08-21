<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('events', function (Blueprint $table) {
            if (! Schema::hasColumn('events', 'participantes_max_m')) {
                $table->unsignedInteger('participantes_max_m')->nullable()->after('participantes_min_m');
            }
            if (! Schema::hasColumn('events', 'participantes_max_f')) {
                $table->unsignedInteger('participantes_max_f')->nullable()->after('participantes_min_f');
            }
        });
    }

    public function down(): void
    {
        Schema::table('events', function (Blueprint $table) {
            foreach (['participantes_max_m', 'participantes_max_f'] as $column) {
                if (Schema::hasColumn('events', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
