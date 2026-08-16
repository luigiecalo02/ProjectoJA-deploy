<?php

namespace App\Modules\Terrains\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOneThrough;

class ZonaTerreno extends Model
{
    protected $table = 'zonas_terreno';

    protected $fillable = [
        'configuracion_terreno_id',
        'nombre',
        'descripcion',
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

    public function lotes(): HasMany
    {
        return $this->hasMany(LoteTerreno::class, 'zona_terreno_id')->orderBy('orden')->orderBy('id');
    }
}
