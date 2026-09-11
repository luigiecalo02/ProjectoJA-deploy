<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('app_settings', 'organizacion_id')) {
            Schema::table('app_settings', function (Blueprint $table) {
                $table->foreignId('organizacion_id')
                    ->nullable()
                    ->after('id')
                    ->constrained('organizacion')
                    ->nullOnDelete();
                $table->unique('organizacion_id');
            });
        }

        if (! Schema::hasColumn('app_settings', 'clubes')) {
            Schema::table('app_settings', function (Blueprint $table) {
                $table->json('clubes')->nullable();
            });
        }

        $defaults = json_encode([
            'source' => 'clubes',
            'scene_theme' => 'night',
            'kicker' => 'Club de Conquistadores',
            'title' => 'CONQUISTADORES',
            'subtitle' => 'Conectados con la misión',
            'motto' => 'Una misión, un propósito',
            'values' => 'Disciplina · Servicio · Amor',
        ], JSON_UNESCAPED_UNICODE);

        DB::table('app_settings')
            ->whereNull('organizacion_id')
            ->whereNull('clubes')
            ->update(['clubes' => $defaults]);
    }

    public function down(): void
    {
        if (Schema::hasColumn('app_settings', 'clubes')) {
            Schema::table('app_settings', function (Blueprint $table) {
                $table->dropColumn('clubes');
            });
        }

        if (Schema::hasColumn('app_settings', 'organizacion_id')) {
            Schema::table('app_settings', function (Blueprint $table) {
                $table->dropUnique(['organizacion_id']);
                $table->dropConstrainedForeignId('organizacion_id');
            });
        }
    }
};
