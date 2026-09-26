<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('productos_servicios') || Schema::hasColumn('productos_servicios', 'icono')) {
            return;
        }

        Schema::table('productos_servicios', function (Blueprint $table) {
            $table->string('icono', 64)->nullable()->after('image_path');
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('productos_servicios') || ! Schema::hasColumn('productos_servicios', 'icono')) {
            return;
        }

        Schema::table('productos_servicios', function (Blueprint $table) {
            $table->dropColumn('icono');
        });
    }
};
