<?php

namespace App\Modules\Events\Models;

use Illuminate\Database\Eloquent\Model;

class Icono extends Model
{
    protected $table = 'iconos';

    protected $fillable = [
        'nombre',
        'slug',
        'categoria',
        'etiquetas',
        'tipo',
        'valor',
        'orden',
        'estado',
        'es_sistema',
    ];

    protected function casts(): array
    {
        return [
            'etiquetas' => 'array',
            'orden' => 'integer',
            'estado' => 'boolean',
            'es_sistema' => 'boolean',
        ];
    }
}
