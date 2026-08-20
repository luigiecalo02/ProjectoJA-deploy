<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('organizacion_ciudad', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organizacion_id')->constrained('organizacion')->cascadeOnDelete();
            $table->foreignId('ciudad_id')->constrained('ciudad')->restrictOnDelete();
            $table->timestamps();

            $table->unique(['organizacion_id', 'ciudad_id'], 'org_ciudad_unique');
            $table->index('ciudad_id');
        });

        $distritos = DB::table('organizacion')
            ->where('tipo_organizacion_id', 3)
            ->whereNotNull('ciudad_id')
            ->get(['id', 'ciudad_id']);

        $now = now();
        foreach ($distritos as $distrito) {
            DB::table('organizacion_ciudad')->updateOrInsert(
                [
                    'organizacion_id' => $distrito->id,
                    'ciudad_id' => $distrito->ciudad_id,
                ],
                [
                    'created_at' => $now,
                    'updated_at' => $now,
                ],
            );
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('organizacion_ciudad');
    }
};
