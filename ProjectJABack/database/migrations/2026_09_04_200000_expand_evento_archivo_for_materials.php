<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('evento_archivo', 'url')) {
            Schema::table('evento_archivo', function (Blueprint $table) {
                $table->string('url', 2048)->nullable()->after('file_id');
                $table->string('titulo', 191)->nullable()->after('url');
            });
        }

        if (Schema::getConnection()->getDriverName() === 'sqlite') {
            return;
        }

        Schema::table('evento_archivo', function (Blueprint $table) {
            $table->dropForeign(['file_id']);
        });
        Schema::table('evento_archivo', function (Blueprint $table) {
            $table->unsignedBigInteger('file_id')->nullable()->change();
            $table->foreign('file_id')->references('id')->on('files')->nullOnDelete();
        });
    }

    public function down(): void
    {
        if (Schema::hasColumn('evento_archivo', 'url')) {
            Schema::table('evento_archivo', function (Blueprint $table) {
                $table->dropColumn(['url', 'titulo']);
            });
        }
    }
};
