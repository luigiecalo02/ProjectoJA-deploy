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

    public function participacion(): BelongsTo
    {
        return $this->belongsTo(EventoParticipacion::class, 'evento_participacion_id');
    }

    public function producto(): BelongsTo
    {
        return $this->belongsTo(ProductoServicio::class, 'producto_servicio_id');
    }
}
