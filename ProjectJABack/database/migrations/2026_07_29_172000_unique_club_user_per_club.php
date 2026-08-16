<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Dejar una sola asociación por club (la más antigua)
        $duplicates = DB::table('club_user')
            ->select('club_id')
            ->groupBy('club_id')
            ->havingRaw('COUNT(*) > 1')
            ->pluck('club_id');

        foreach ($duplicates as $clubId) {
            $keepUserId = DB::table('club_user')
                ->where('club_id', $clubId)
                ->orderBy('created_at')
                ->orderBy('user_id')
                ->value('user_id');

            DB::table('club_user')
                ->where('club_id', $clubId)
                ->where('user_id', '!=', $keepUserId)
                ->delete();
        }

        Schema::table('club_user', function (Blueprint $table) {
            $table->unique('club_id', 'club_user_club_id_unique');
        });
    }

    public function down(): void
    {
        Schema::table('club_user', function (Blueprint $table) {
            $table->dropUnique('club_user_club_id_unique');
        });
    }
};
