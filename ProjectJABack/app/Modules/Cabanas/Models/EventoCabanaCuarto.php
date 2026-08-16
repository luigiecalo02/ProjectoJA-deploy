<?php

namespace App\Modules\Cabanas\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class EventoCabanaCuarto extends Model
{
    protected $table = 'evento_cabana_cuartos';

    protected $fillable = [
        'evento_cabana_piso_id', 'cabana_cuarto_id', 'nombre', 'codigo',
        'x', 'y', 'ancho', 'alto', 'genero', 'capacidad', 'orden',
    ];

    protected function casts(): array
    {
        return [
            'x' => 'float', 'y' => 'float', 'ancho' => 'float', 'alto' => 'float',
            'capacidad' => 'integer', 'orden' => 'integer',
        ];
    }

    public function piso(): BelongsTo
    {
        return $this->belongsTo(EventoCabanaPiso::class, 'evento_cabana_piso_id');
    }

    public function camas(): HasMany
    {
        return $this->hasMany(EventoCabanaCama::class)->orderBy('orden');
    }
}
