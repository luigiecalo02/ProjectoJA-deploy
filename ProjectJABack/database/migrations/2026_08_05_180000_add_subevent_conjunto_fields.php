<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('events', function (Blueprint $table) {
            $table->boolean('es_conjunto')->default(false)->after('equipos_org_max');
            $table->string('nivel_conjunto', 32)->nullable()->after('es_conjunto');
        });
    }

    public function down(): void
    {
        Schema::table('events', function (Blueprint $table) {
            $table->dropColumn(['es_conjunto', 'nivel_conjunto']);
        });
    }
};
