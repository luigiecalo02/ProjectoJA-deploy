<?php

namespace App\Modules\Terrains\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOneThrough;

class LoteTerreno extends Model
{
    protected $table = 'lotes_terreno';

    public const TIPO_CALCULADA = 'calculada';

    public const TIPO_MANUAL = 'manual';

    protected $fillable = [
        'configuracion_terreno_id',
        'zona_terreno_id',
        'codigo',
        'nombre',
        'descripcion',
        'geometria',
        'area',
        'perimetro',
        'capacidad_calculada',
        'capacidad_maxima',
        'tipo_capacidad',
        'orden',
        'estado',
    ];

    protected function casts(): array
    {
        return [
            'geometria' => 'array',
            'area' => 'float',
            'perimetro' => 'float',
            'capacidad_calculada' => 'integer',
            'capacidad_maxima' => 'integer',
            'orden' => 'integer',
        ];
    }

    public function configuracion(): BelongsTo
    {
        return $this->belongsTo(ConfiguracionTerreno::class, 'configuracion_terreno_id');
    }

    public function terreno(): HasOneThrough
    {
        return $this->hasOneThrough(
            Terreno::class,
            ConfiguracionTerreno::class,
            'id',
            'id',
            'configuracion_terreno_id',
            'terreno_id'
        );
    }

    public function zona(): BelongsTo
    {
        return $this->belongsTo(ZonaTerreno::class, 'zona_terreno_id');
    }

    public function eventosLotes(): HasMany
    {
        return $this->hasMany(EventoLote::class, 'lote_terreno_id');
    }
}
