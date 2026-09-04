<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('events', function (Blueprint $table) {
            if (! Schema::hasColumn('events', 'categoria_ids')) {
                $table->json('categoria_ids')->nullable()->after('categoria_subevento_id');
            }
            if (! Schema::hasColumn('events', 'criterio_disponible_ids')) {
                $table->json('criterio_disponible_ids')->nullable()->after('categoria_ids');
            }
        });
    }

    public function down(): void
    {
        Schema::table('events', function (Blueprint $table) {
            foreach (['categoria_ids', 'criterio_disponible_ids'] as $column) {
                if (Schema::hasColumn('events', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
