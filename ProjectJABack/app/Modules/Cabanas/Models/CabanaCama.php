<?php

namespace App\Modules\Cabanas\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CabanaCama extends Model
{
    public const ESTADOS = ['disponible', 'no_disponible', 'mantenimiento'];

    protected $table = 'cabana_camas';

    protected $fillable = [
        'cabana_cuarto_id', 'codigo', 'nombre', 'capacidad', 'x', 'y', 'ancho', 'alto',
        'rotacion', 'estado', 'orden',
    ];

    protected function casts(): array
    {
        return ['capacidad' => 'integer', 'x' => 'float', 'y' => 'float', 'ancho' => 'float', 'alto' => 'float', 'rotacion' => 'float'];
    }

    public function cuarto(): BelongsTo
    {
        return $this->belongsTo(CabanaCuarto::class, 'cabana_cuarto_id');
    }
}
