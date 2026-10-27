<?php

namespace App\Modules\Events\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class EventoParticipacionVenta extends Model
{
    protected $table = 'evento_participacion_venta';

    protected $fillable = [
        'evento_participacion_id',
        'producto_servicio_id',
        'cantidad',
        'cantidad_entregada',
    ];

    protected function casts(): array
    {
        return [
            'cantidad' => 'integer',
            'cantidad_entregada' => 'integer',
        ];
    }

    public function cantidadContable(): int
    {
        return $this->cantidad_entregada === null
            ? (int) $this->cantidad
            : (int) $this->cantidad_entregada;
    }

    /**
     * @return array<int, int>
     */
    public static function totalesPorProducto(int $eventoId, bool $usarEntregadas = false): array
    {
        $qtySql = $usarEntregadas
            ? 'SUM(CASE WHEN evento_participacion_venta.cantidad_entregada IS NULL THEN evento_participacion_venta.cantidad ELSE evento_participacion_venta.cantidad_entregada END)'
            : 'SUM(evento_participacion_venta.cantidad)';

        $rows = static::query()
            ->join(
                'evento_participacion',
                'evento_participacion.id',
                '=',
                'evento_participacion_venta.evento_participacion_id',
            )
            ->where('evento_participacion.evento_id', $eventoId)
            ->where('evento_participacion.participa', true)
            ->groupBy('evento_participacion_venta.producto_servicio_id')
            ->selectRaw("evento_participacion_venta.producto_servicio_id as producto_id, {$qtySql} as total")
            ->get();

        $totales = [];
        foreach ($rows as $row) {
            $totales[(int) $row->producto_id] = (int) $row->total;
        }

        return $totales;
    }

    public function participacion(): BelongsTo
    {
        return $this->belongsTo(EventoParticipacion::class, 'evento_participacion_id');
    }

    public function producto(): BelongsTo
    {
        return $this->belongsTo(ProductoServicio::class, 'producto_servicio_id');
    }
}
