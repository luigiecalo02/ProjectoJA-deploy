<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('roles', function (Blueprint $table) {
            $table->boolean('is_system')->default(false)->after('display_name');
            $table->boolean('is_super')->default(false)->after('is_system');
            $table->text('description')->nullable()->after('is_super');
        });

        Schema::create('pages', function (Blueprint $table) {
            $table->id();
            $table->string('key')->unique();
            $table->string('name');
            $table->string('route_name')->nullable();
            $table->string('icon')->nullable();
            $table->unsignedInteger('sort_order')->default(0);
            $table->boolean('is_active')->default(true);
            $table->text('description')->nullable();
            $table->timestamps();
        });

        Schema::table('permissions', function (Blueprint $table) {
            $table->foreignId('page_id')->nullable()->after('module')->constrained('pages')->nullOnDelete();
            $table->string('action')->nullable()->after('page_id');
            $table->unsignedInteger('sort_order')->default(0)->after('action');
        });
    }

    public function down(): void
    {
        Schema::table('permissions', function (Blueprint $table) {
            $table->dropConstrainedForeignId('page_id');
            $table->dropColumn(['action', 'sort_order']);
        });

        Schema::dropIfExists('pages');

        Schema::table('roles', function (Blueprint $table) {
            $table->dropColumn(['is_system', 'is_super', 'description']);
        });
    }
};
