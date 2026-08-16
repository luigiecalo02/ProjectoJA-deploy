<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('personas', function (Blueprint $table) {
            $table->foreignId('user_id')->nullable()->after('id')->constrained('users')->nullOnDelete();
        });

        // Limpiar cargos antiguos de ministerios en club_directors (se reemplazan por directiva)
        DB::table('club_directors')
            ->whereIn('ministry', ['conquistadores', 'aventureros', 'guias_mayores'])
            ->delete();
    }

    public function down(): void
    {
        Schema::table('personas', function (Blueprint $table) {
            $table->dropConstrainedForeignId('user_id');
        });
    }
};
