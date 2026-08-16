<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('evento_inscripcion_persona', function (Blueprint $table) {
            $table->string('cargo_directiva', 32)->nullable()->after('tipo');
        });
    }

    public function down(): void
    {
        Schema::table('evento_inscripcion_persona', function (Blueprint $table) {
            $table->dropColumn('cargo_directiva');
        });
    }
};
