<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::table('events')
            ->where('requiere_seguro', false)
            ->whereExists(function ($query) {
                $query->selectRaw('1')
                    ->from('seguros')
                    ->whereColumn('seguros.evento_id', 'events.id');
            })
            ->orderBy('id')
            ->each(function ($event) {
                $insurance = DB::table('seguros')
                    ->where('evento_id', $event->id)
                    ->orderByDesc('id')
                    ->first();

                if (! $insurance) {
                    return;
                }

                DB::table('events')
                    ->where('id', $event->id)
                    ->update([
                        'requiere_seguro' => true,
                        'tipo_seguro_id' => $insurance->tipo_seguro_id,
                        'seguro_valor' => $insurance->valor,
                        'seguro_fecha_inicio' => $insurance->fecha_inicio,
                        'seguro_fecha_fin' => $insurance->fecha_fin,
                        'updated_at' => now(),
                    ]);
            });
    }

    public function down(): void
    {
        // No se elimina una configuración recuperada desde coberturas existentes.
    }
};
