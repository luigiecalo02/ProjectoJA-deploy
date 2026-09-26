<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('productos_servicios') || Schema::hasColumn('productos_servicios', 'organizacion_id')) {
            return;
        }

        Schema::table('productos_servicios', function (Blueprint $table) {
            $table->unsignedBigInteger('organizacion_id')->nullable()->after('id');
            $table->index('organizacion_id');
            $table->foreign('organizacion_id')
                ->references('id')
                ->on('organizacion')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('productos_servicios') || ! Schema::hasColumn('productos_servicios', 'organizacion_id')) {
            return;
        }

        Schema::table('productos_servicios', function (Blueprint $table) {
            $table->dropForeign(['organizacion_id']);
            $table->dropIndex(['organizacion_id']);
            $table->dropColumn('organizacion_id');
        });
    }
};
