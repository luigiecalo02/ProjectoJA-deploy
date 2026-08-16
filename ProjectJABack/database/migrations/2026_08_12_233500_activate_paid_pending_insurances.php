<?php

use App\Modules\Events\Models\EventoPago;
use App\Modules\Events\Models\Seguro;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::table('evento_inscripcion')
            ->whereExists(function ($query) {
                $query->selectRaw('1')
                    ->from('seguros')
                    ->whereColumn('seguros.inscripcion_id', 'evento_inscripcion.id')
                    ->where('seguros.estado', Seguro::ESTADO_PENDIENTE);
            })
            ->orderBy('id')
            ->each(function ($inscripcion) {
                $totalAprobado = (float) DB::table('evento_inscripcion_comprobante')
                    ->where('inscripcion_id', $inscripcion->id)
                    ->where('estado', 'aprobado')
                    ->sum('valor');

                if ($totalAprobado + 0.01 < (float) ($inscripcion->total_declarado ?? 0)) {
                    return;
                }

                $seguroIds = DB::table('seguros')
                    ->where('inscripcion_id', $inscripcion->id)
                    ->where('estado', Seguro::ESTADO_PENDIENTE)
                    ->pluck('id');

                DB::table('seguros')
                    ->whereIn('id', $seguroIds)
                    ->update([
                        'estado' => Seguro::ESTADO_ACTIVO,
                        'fecha_pago' => now(),
                        'updated_at' => now(),
                    ]);

                DB::table('evento_pago')
                    ->where('pagable_type', Seguro::class)
                    ->whereIn('pagable_id', $seguroIds)
                    ->where('estado', EventoPago::ESTADO_PENDIENTE)
                    ->update([
                        'estado' => EventoPago::ESTADO_PAGADO,
                        'pagado_at' => now(),
                        'updated_at' => now(),
                    ]);
            });
    }

    public function down(): void
    {
        // No se revierte una cobertura que ya fue confirmada como pagada.
    }
};
