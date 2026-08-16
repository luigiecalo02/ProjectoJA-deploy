<?php

namespace App\Modules\Events\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class TipoEvento extends Model
{
    protected $table = 'tipo_evento';

    protected $fillable = [
        'nombre',
        'slug',
        'descripcion',
        'color',
        'icono',
        'orden',
        'estado',
    ];

    protected function casts(): array
    {
        return [
            'estado' => 'boolean',
            'orden' => 'integer',
        ];
    }

    public function events(): HasMany
    {
        return $this->hasMany(Event::class, 'tipo_evento_id');
    }
}
