<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('clubes_evento_presupuestos')) {
            return;
        }

        $this->dropEventoUnique();

        Schema::table('clubes_evento_presupuestos', function (Blueprint $table) {
            if (! Schema::hasColumn('clubes_evento_presupuestos', 'nombre')) {
                $table->string('nombre')->default('Presupuesto');
            }
            if (! Schema::hasColumn('clubes_evento_presupuestos', 'activo')) {
                $table->boolean('activo')->default(false);
            }
        });

        try {
            Schema::table('clubes_evento_presupuestos', function (Blueprint $table) {
                $table->index(['evento_id', 'activo'], 'clubes_evento_presupuestos_evento_activo_idx');
            });
        } catch (\Throwable) {
            // index already exists
        }

        DB::table('clubes_evento_presupuestos')
            ->where(function ($query) {
                $query->where('nombre', '')->orWhereNull('nombre');
            })
            ->update(['nombre' => 'Presupuesto']);

        $eventoIds = DB::table('clubes_evento_presupuestos')->distinct()->pluck('evento_id');
        foreach ($eventoIds as $eventoId) {
            $hasActive = DB::table('clubes_evento_presupuestos')
                ->where('evento_id', $eventoId)
                ->where('activo', true)
                ->exists();
            if ($hasActive) {
                continue;
            }
            $id = DB::table('clubes_evento_presupuestos')
                ->where('evento_id', $eventoId)
                ->orderBy('id')
                ->value('id');
            if ($id) {
                DB::table('clubes_evento_presupuestos')->where('id', $id)->update(['activo' => true]);
            }
        }
    }

    public function down(): void
    {
        if (! Schema::hasTable('clubes_evento_presupuestos')) {
            return;
        }

        try {
            Schema::table('clubes_evento_presupuestos', function (Blueprint $table) {
                $table->dropIndex('clubes_evento_presupuestos_evento_activo_idx');
            });
        } catch (\Throwable) {
        }

        Schema::table('clubes_evento_presupuestos', function (Blueprint $table) {
            if (Schema::hasColumn('clubes_evento_presupuestos', 'activo')) {
                $table->dropColumn('activo');
            }
            if (Schema::hasColumn('clubes_evento_presupuestos', 'nombre')) {
                $table->dropColumn('nombre');
            }
        });
    }

    private function dropEventoUnique(): void
    {
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
};
