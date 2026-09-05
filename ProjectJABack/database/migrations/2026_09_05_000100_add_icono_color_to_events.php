<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('events')) {
            return;
        }

        Schema::table('events', function (Blueprint $table) {
            if (! Schema::hasColumn('events', 'icono')) {
                $table->string('icono', 64)->nullable()->after('image_url');
            }
            if (! Schema::hasColumn('events', 'color')) {
                $table->string('color', 32)->nullable()->after('icono');
            }
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('events')) {
            return;
        }

        Schema::table('events', function (Blueprint $table) {
            if (Schema::hasColumn('events', 'color')) {
                $table->dropColumn('color');
            }
            if (Schema::hasColumn('events', 'icono')) {
                $table->dropColumn('icono');
            }
        });
    }
};
