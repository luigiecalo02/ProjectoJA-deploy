<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('pais', function (Blueprint $table) {
            $table->string('codigo', 3)->nullable()->after('id');
            $table->unique('codigo');
        });

        Schema::table('departamento', function (Blueprint $table) {
            $table->string('codigo', 2)->nullable()->after('pais_id');
            $table->unique('codigo');
        });

        Schema::table('ciudad', function (Blueprint $table) {
            $table->string('codigo', 5)->nullable()->after('departamento_id');
            $table->unique('codigo');
        });
    }

    public function down(): void
    {
        Schema::table('ciudad', function (Blueprint $table) {
            $table->dropUnique(['codigo']);
            $table->dropColumn('codigo');
        });

        Schema::table('departamento', function (Blueprint $table) {
            $table->dropUnique(['codigo']);
            $table->dropColumn('codigo');
        });

        Schema::table('pais', function (Blueprint $table) {
            $table->dropUnique(['codigo']);
            $table->dropColumn('codigo');
        });
    }
};
