<?php

namespace App\Modules\Events\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ProductoServicio extends Model
{
    protected $table = 'productos_servicios';

    protected $fillable = [
        'nombre',
        'tipo',
        'descripcion',
        'precio',
        'unidad',
        'activo',
    ];

    protected function casts(): array
    {
        return [
            'precio' => 'decimal:2',
            'activo' => 'boolean',
        ];
    }

    public function ofertasEvento(): HasMany
    {
        return $this->hasMany(EventoProductoServicio::class, 'producto_servicio_id');
    }
}
