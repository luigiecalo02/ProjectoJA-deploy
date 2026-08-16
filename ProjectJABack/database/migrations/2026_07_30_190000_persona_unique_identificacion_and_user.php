<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Liberar identificaciones duplicadas (dejar la más antigua)
        $dupIds = DB::table('personas')
            ->select('identificacion')
            ->whereNull('deleted_at')
            ->groupBy('identificacion')
            ->havingRaw('COUNT(*) > 1')
            ->pluck('identificacion');

        foreach ($dupIds as $identificacion) {
            $ids = DB::table('personas')
                ->where('identificacion', $identificacion)
                ->whereNull('deleted_at')
                ->orderBy('id')
                ->pluck('id');

            foreach ($ids->slice(1) as $id) {
                DB::table('personas')
                    ->where('id', $id)
                    ->update(['identificacion' => $identificacion.'-dup-'.$id]);
            }
        }

        // Un solo user_id por persona: liberar duplicados
        $dupUsers = DB::table('personas')
            ->select('user_id')
            ->whereNotNull('user_id')
            ->whereNull('deleted_at')
            ->groupBy('user_id')
            ->havingRaw('COUNT(*) > 1')
            ->pluck('user_id');

        foreach ($dupUsers as $userId) {
            $ids = DB::table('personas')
                ->where('user_id', $userId)
                ->whereNull('deleted_at')
                ->orderBy('id')
                ->pluck('id');

            foreach ($ids->slice(1) as $id) {
                DB::table('personas')->where('id', $id)->update(['user_id' => null]);
            }
        }

        Schema::table('personas', function (Blueprint $table) {
            $table->dropUnique(['tipo_identificacion', 'identificacion']);
            $table->unique('identificacion');
            $table->unique('user_id');
        });
    }

    public function down(): void
    {
        Schema::table('personas', function (Blueprint $table) {
            $table->dropUnique(['identificacion']);
            $table->dropUnique(['user_id']);
            $table->unique(['tipo_identificacion', 'identificacion']);
        });
    }
};
