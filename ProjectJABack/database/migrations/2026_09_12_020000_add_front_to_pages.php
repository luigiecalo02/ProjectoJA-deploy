<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('pages') || Schema::hasColumn('pages', 'front')) {
            return;
        }

        Schema::table('pages', function (Blueprint $table) {
            $table->string('front', 16)->default('project')->after('is_active');
        });

        DB::table('pages')->whereNull('front')->orWhere('front', '')->update(['front' => 'project']);
    }

    public function down(): void
    {
        if (! Schema::hasTable('pages') || ! Schema::hasColumn('pages', 'front')) {
            return;
        }

        Schema::table('pages', function (Blueprint $table) {
            $table->dropColumn('front');
        });
    }
};
