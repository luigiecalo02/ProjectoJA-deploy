<?php

namespace App\Modules\Cabanas\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CabanaCama extends Model
{
    public const ESTADOS = ['disponible', 'no_disponible', 'mantenimiento'];

    public const TIPOS = ['sencilla', 'doble', 'multiple', 'camarote'];

    public const NIVELES_CAMAROTE = ['abajo', 'arriba'];

    protected $table = 'cabana_camas';

    protected $fillable = [
        'cabana_cuarto_id', 'codigo', 'nombre', 'capacidad', 'tipo', 'nivel_camarote',
        'grupo_camarote', 'precio_sugerido', 'x', 'y', 'ancho', 'alto',
        'rotacion', 'estado', 'orden',
    ];

    protected function casts(): array
    {
        return [
            'capacidad' => 'integer',
            'precio_sugerido' => 'decimal:2',
            'x' => 'float',
            'y' => 'float',
            'ancho' => 'float',
            'alto' => 'float',
            'rotacion' => 'float',
        ];
    }

    public function cuarto(): BelongsTo
    {
        return $this->belongsTo(CabanaCuarto::class, 'cabana_cuarto_id');
    }
}
