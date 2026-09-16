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

        $now = now();
        DB::table('tipo_evento')->updateOrInsert(
            ['slug' => 'actividad-economica'],
            [
                'nombre' => 'Actividad Económica',
                'descripcion' => 'Actividades de recaudo, ventas y gestión económica del club',
                'color' => '#b45309',
                'icono' => 'pi pi-wallet',
                'orden' => 10,
                'estado' => true,
                'created_at' => $now,
                'updated_at' => $now,
            ]
        );
    }

    public function down(): void
    {
        if (! Schema::hasTable('tipo_evento')) {
            return;
        }

        DB::table('tipo_evento')->where('slug', 'actividad-economica')->delete();
    }
};
