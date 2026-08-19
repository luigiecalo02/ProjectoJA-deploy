<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('cabanas', function (Blueprint $table) {
            $table->string('image_url', 2048)->nullable()->after('descripcion');
        });

        Schema::table('evento_cabanas', function (Blueprint $table) {
            $table->string('image_url', 2048)->nullable()->after('descripcion');
        });
    }

    public function down(): void
    {
        Schema::table('cabanas', function (Blueprint $table) {
            $table->dropColumn('image_url');
        });

        Schema::table('evento_cabanas', function (Blueprint $table) {
            $table->dropColumn('image_url');
        });
    }
};
