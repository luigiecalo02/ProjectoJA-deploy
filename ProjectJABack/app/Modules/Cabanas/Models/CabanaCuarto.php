<?php

namespace App\Modules\Cabanas\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class CabanaCuarto extends Model
{
    public const GENEROS = ['M', 'F', 'MIXTO'];

    protected $table = 'cabana_cuartos';

    protected $fillable = [
        'cabana_piso_id', 'nombre', 'codigo', 'x', 'y', 'ancho', 'alto',
        'genero', 'capacidad', 'orden',
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
        return $this->belongsTo(CabanaPiso::class, 'cabana_piso_id');
    }

    public function camas(): HasMany
    {
        return $this->hasMany(CabanaCama::class)->orderBy('orden');
    }
}
