<?php

namespace App\Modules\Terrains\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class EventoEstructura extends Model
{
    protected $table = 'eventos_estructuras';

    protected $fillable = [
        'evento_terreno_id',
        'estructura_terreno_id',
        'nombre',
        'tipo',
        'geometria',
        'area',
        'perimetro',
        'color',
        'orden',
        'estado',
    ];

    protected function casts(): array
    {
        return [
            'geometria' => 'array',
            'area' => 'float',
            'perimetro' => 'float',
            'orden' => 'integer',
        ];
    }

    public function eventoTerreno(): BelongsTo
    {
        return $this->belongsTo(EventoTerreno::class, 'evento_terreno_id');
    }

    public function estructuraBase(): BelongsTo
    {
        return $this->belongsTo(EstructuraTerreno::class, 'estructura_terreno_id');
    }
}
