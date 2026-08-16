<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->foreignId('active_organizacion_id')
                ->nullable()
                ->after('persona_id')
                ->constrained('organizacion')
                ->nullOnDelete();

            $table->foreignId('active_rol_id')
                ->nullable()
                ->after('active_organizacion_id')
                ->constrained('roles')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropConstrainedForeignId('active_organizacion_id');
            $table->dropConstrainedForeignId('active_rol_id');
        });
    }
};
