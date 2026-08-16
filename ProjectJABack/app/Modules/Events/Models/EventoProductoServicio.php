<?php

namespace App\Modules\Events\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class EventoProductoServicio extends Model
{
    protected $table = 'evento_producto_servicio';

    protected $fillable = [
        'evento_id',
        'producto_servicio_id',
        'precio',
        'activo',
    ];

    protected function casts(): array
    {
        return [
            'precio' => 'decimal:2',
            'activo' => 'boolean',
        ];
    }

    public function evento(): BelongsTo
    {
        return $this->belongsTo(Event::class, 'evento_id');
    }

    public function producto(): BelongsTo
    {
        return $this->belongsTo(ProductoServicio::class, 'producto_servicio_id');
    }

    public function reservas(): HasMany
    {
        return $this->hasMany(EventoServicioReserva::class, 'evento_producto_servicio_id');
    }
}
