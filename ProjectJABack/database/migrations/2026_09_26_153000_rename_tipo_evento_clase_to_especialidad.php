<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('tipo_evento')) {
            return;
        }

        DB::table('tipo_evento')
            ->where('slug', 'clase')
            ->update([
                'nombre' => 'Especialidad',
                'descripcion' => 'Especialidades, talleres y sesiones de instrucción',
                'updated_at' => now(),
            ]);
    }

    public function down(): void
    {
        if (! Schema::hasTable('tipo_evento')) {
            return;
        }

        DB::table('tipo_evento')
            ->where('slug', 'clase')
            ->update([
                'nombre' => 'Clase',
                'descripcion' => 'Clases, talleres y sesiones de instrucción',
                'updated_at' => now(),
            ]);
    }
};
