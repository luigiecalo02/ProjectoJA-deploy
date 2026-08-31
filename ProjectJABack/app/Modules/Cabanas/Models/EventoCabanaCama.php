<?php

namespace App\Modules\Cabanas\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class EventoCabanaCama extends Model
{
    protected $table = 'evento_cabana_camas';

    protected $fillable = [
        'evento_cabana_cuarto_id', 'cabana_cama_id', 'codigo', 'nombre', 'capacidad',
        'tipo', 'nivel_camarote', 'grupo_camarote', 'precio_sugerido', 'precio',
        'x', 'y', 'ancho', 'alto', 'rotacion', 'estado', 'orden',
    ];

    protected function casts(): array
    {
        return [
            'capacidad' => 'integer',
            'precio_sugerido' => 'decimal:2',
            'precio' => 'decimal:2',
            'x' => 'float',
            'y' => 'float',
            'ancho' => 'float',
            'alto' => 'float',
            'rotacion' => 'float',
        ];
    }

    public function cuarto(): BelongsTo
    {
        return $this->belongsTo(EventoCabanaCuarto::class, 'evento_cabana_cuarto_id');
    }

    public function asignaciones(): HasMany
    {
        return $this->hasMany(AsignacionCama::class, 'evento_cabana_cama_id');
    }
}
