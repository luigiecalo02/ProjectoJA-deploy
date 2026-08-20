<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('organizacion', function (Blueprint $table) {
            $table->string('estado_aprobacion', 20)->default('aprobada')->after('estado');
            $table->text('revision_observacion')->nullable()->after('estado_aprobacion');
            $table->foreignId('revisado_por')->nullable()->after('revision_observacion')->constrained('users')->nullOnDelete();
            $table->timestamp('revisado_en')->nullable()->after('revisado_por');
            $table->index('estado_aprobacion');
        });

        DB::table('organizacion')->whereNull('estado_aprobacion')->update([
            'estado_aprobacion' => 'aprobada',
        ]);
    }

    public function down(): void
    {
        Schema::table('organizacion', function (Blueprint $table) {
            $table->dropConstrainedForeignId('revisado_por');
            $table->dropColumn(['estado_aprobacion', 'revision_observacion', 'revisado_en']);
        });
    }
};
