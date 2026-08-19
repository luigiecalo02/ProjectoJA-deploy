<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('cabana_cuartos', function (Blueprint $table) {
            $table->string('forma', 16)->default('rect')->after('alto');
            $table->json('vertices')->nullable()->after('forma');
            $table->json('puertas')->nullable()->after('vertices');
        });
        Schema::table('evento_cabana_cuartos', function (Blueprint $table) {
            $table->string('forma', 16)->default('rect')->after('alto');
            $table->json('vertices')->nullable()->after('forma');
            $table->json('puertas')->nullable()->after('vertices');
        });
    }

    public function down(): void
    {
        Schema::table('cabana_cuartos', function (Blueprint $table) {
            $table->dropColumn(['forma', 'vertices', 'puertas']);
        });
        Schema::table('evento_cabana_cuartos', function (Blueprint $table) {
            $table->dropColumn(['forma', 'vertices', 'puertas']);
        });
    }
};
