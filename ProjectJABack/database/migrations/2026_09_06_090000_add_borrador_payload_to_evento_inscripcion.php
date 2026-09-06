<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('evento_inscripcion', function (Blueprint $table) {
            $table->json('borrador_payload')->nullable()->after('observacion_revision');
        });
    }

    public function down(): void
    {
        Schema::table('evento_inscripcion', function (Blueprint $table) {
            $table->dropColumn('borrador_payload');
        });
    }
};
