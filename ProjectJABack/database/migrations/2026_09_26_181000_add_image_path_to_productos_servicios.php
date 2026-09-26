<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('productos_servicios') || Schema::hasColumn('productos_servicios', 'image_path')) {
            return;
        }

        Schema::table('productos_servicios', function (Blueprint $table) {
            $table->string('image_path')->nullable()->after('unidad');
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('productos_servicios') || ! Schema::hasColumn('productos_servicios', 'image_path')) {
            return;
        }

        Schema::table('productos_servicios', function (Blueprint $table) {
            $table->dropColumn('image_path');
        });
    }
};
