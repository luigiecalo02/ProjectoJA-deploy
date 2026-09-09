<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $now = now();

        DB::table('tipo_organizacion')->updateOrInsert(
            ['id' => 9],
            [
                'tipo_organizacion_padre_id' => 2,
                'nombre' => 'Zona',
                'descripcion' => 'Hijo de Asociación; agrupa municipios y es padre del Distrito',
                'estado' => true,
                'created_at' => $now,
                'updated_at' => $now,
            ]
        );

        DB::table('tipo_organizacion')
            ->where('id', 3)
            ->update([
                'tipo_organizacion_padre_id' => 9,
                'descripcion' => 'Hijo de Zona',
                'updated_at' => $now,
            ]);
    }

    public function down(): void
    {
        DB::table('tipo_organizacion')
            ->where('id', 3)
            ->update([
                'tipo_organizacion_padre_id' => 2,
                'descripcion' => 'Hijo de Asociación',
                'updated_at' => now(),
            ]);

        DB::table('tipo_organizacion')->where('id', 9)->delete();
    }
};
