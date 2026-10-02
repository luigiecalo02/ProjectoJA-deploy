<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('clubes_evento_presupuestos')) {
            return;
        }

        $hasUnique = collect(Schema::getIndexes('clubes_evento_presupuestos'))
            ->contains(fn (array $index) => ($index['unique'] ?? false)
                && ($index['columns'] ?? []) === ['evento_id']);
        if (! $hasUnique) {
            return;
        }

        try {
            Schema::table('clubes_evento_presupuestos', function (Blueprint $table) {
                $table->dropForeign(['evento_id']);
            });
        } catch (\Throwable) {
        }

        Schema::table('clubes_evento_presupuestos', function (Blueprint $table) {
            $table->dropUnique(['evento_id']);
        });

        Schema::table('clubes_evento_presupuestos', function (Blueprint $table) {
            $table->foreign('evento_id')->references('id')->on('events')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('clubes_evento_presupuestos')) {
            return;
        }

        $hasUnique = collect(Schema::getIndexes('clubes_evento_presupuestos'))
            ->contains(fn (array $index) => ($index['unique'] ?? false)
                && ($index['columns'] ?? []) === ['evento_id']);
        if ($hasUnique) {
            return;
        }

        $hasMany = Schema::getConnection()
            ->table('clubes_evento_presupuestos')
            ->select('evento_id')
            ->groupBy('evento_id')
            ->havingRaw('COUNT(*) > 1')
            ->exists();
        if ($hasMany) {
            return;
        }

        try {
            Schema::table('clubes_evento_presupuestos', function (Blueprint $table) {
                $table->dropForeign(['evento_id']);
            });
        } catch (\Throwable) {
        }

        Schema::table('clubes_evento_presupuestos', function (Blueprint $table) {
            $table->unique('evento_id');
        });

        Schema::table('clubes_evento_presupuestos', function (Blueprint $table) {
            $table->foreign('evento_id')->references('id')->on('events')->cascadeOnDelete();
        });
    }
};
