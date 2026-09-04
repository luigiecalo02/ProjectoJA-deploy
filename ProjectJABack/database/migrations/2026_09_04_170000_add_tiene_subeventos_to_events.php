<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('events', 'tiene_subeventos')) {
            Schema::table('events', function (Blueprint $table) {
                $table->boolean('tiene_subeventos')->default(false)->after('es_calificable');
            });
        }

        $parentIds = DB::table('events')
            ->whereNotNull('evento_padre_id')
            ->distinct()
            ->pluck('evento_padre_id');

        if ($parentIds->isNotEmpty()) {
            DB::table('events')
                ->whereIn('id', $parentIds)
                ->update(['tiene_subeventos' => true]);
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('events', 'tiene_subeventos')) {
            Schema::table('events', function (Blueprint $table) {
                $table->dropColumn('tiene_subeventos');
            });
        }
    }
};
