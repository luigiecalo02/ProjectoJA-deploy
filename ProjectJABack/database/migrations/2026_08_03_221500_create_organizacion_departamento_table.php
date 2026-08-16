<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('organizacion_departamento', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organizacion_id')->constrained('organizacion')->cascadeOnDelete();
            $table->foreignId('departamento_id')->constrained('departamento')->restrictOnDelete();
            $table->timestamps();

            $table->unique(['organizacion_id', 'departamento_id'], 'org_departamento_unique');
            $table->index('departamento_id');
        });

        // Migrar departamentos únicos ya guardados en asociaciones.
        $asociaciones = DB::table('organizacion')
            ->where('tipo_organizacion_id', 2)
            ->whereNotNull('departamento_id')
            ->get(['id', 'departamento_id']);

        $now = now();
        foreach ($asociaciones as $asociacion) {
            DB::table('organizacion_departamento')->updateOrInsert(
                [
                    'organizacion_id' => $asociacion->id,
                    'departamento_id' => $asociacion->departamento_id,
                ],
                [
                    'created_at' => $now,
                    'updated_at' => $now,
                ],
            );
        }

        // La asociación ya no guarda un solo departamento_id.
        DB::table('organizacion')
            ->where('tipo_organizacion_id', 2)
            ->update(['departamento_id' => null]);
    }

    public function down(): void
    {
        Schema::dropIfExists('organizacion_departamento');
    }
};
