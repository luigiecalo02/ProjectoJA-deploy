<?php

namespace App\Modules\Events\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class CategoriaSubevento extends Model
{
    protected $table = 'categoria_subevento';

    protected $fillable = [
        'nombre',
        'slug',
        'color',
        'icono',
        'orden',
        'estado',
        'maneja_puntos',
        'maneja_fecha_inicio',
        'maneja_fecha_fin',
    ];

    protected function casts(): array
    {
        return [
            'estado' => 'boolean',
            'orden' => 'integer',
            'maneja_puntos' => 'boolean',
            'maneja_fecha_inicio' => 'boolean',
            'maneja_fecha_fin' => 'boolean',
        ];
    }

    public function events(): HasMany
    {
        return $this->hasMany(Event::class, 'categoria_subevento_id');
    }
}
