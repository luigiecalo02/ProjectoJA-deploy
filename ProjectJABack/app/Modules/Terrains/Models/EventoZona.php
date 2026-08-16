<?php

namespace App\Modules\Terrains\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class EventoZona extends Model
{
    protected $table = 'eventos_zonas';

    protected $fillable = [
        'evento_terreno_id',
        'zona_terreno_id',
        'nombre',
        'geometria',
        'area',
        'perimetro',
        'capacidad',
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
            'capacidad' => 'integer',
            'orden' => 'integer',
        ];
    }

    public function eventoTerreno(): BelongsTo
    {
        return $this->belongsTo(EventoTerreno::class, 'evento_terreno_id');
    }

    public function zonaBase(): BelongsTo
    {
        return $this->belongsTo(ZonaTerreno::class, 'zona_terreno_id');
    }

    public function lotes(): HasMany
    {
        return $this->hasMany(EventoLote::class, 'evento_zona_id')->orderBy('orden')->orderBy('id');
    }
}
