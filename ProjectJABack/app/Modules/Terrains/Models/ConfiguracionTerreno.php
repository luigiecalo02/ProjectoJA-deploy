<?php

namespace App\Modules\Terrains\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ConfiguracionTerreno extends Model
{
    protected $table = 'configuraciones_terreno';

    protected $fillable = [
        'terreno_id',
        'nombre',
        'descripcion',
        'es_default',
        'orden',
        'estado',
    ];

    protected function casts(): array
    {
        return [
            'es_default' => 'boolean',
            'orden' => 'integer',
        ];
    }

    public function terreno(): BelongsTo
    {
        return $this->belongsTo(Terreno::class, 'terreno_id');
    }

    public function zonas(): HasMany
    {
        return $this->hasMany(ZonaTerreno::class, 'configuracion_terreno_id')->orderBy('orden')->orderBy('id');
    }

    public function lotes(): HasMany
    {
        return $this->hasMany(LoteTerreno::class, 'configuracion_terreno_id')->orderBy('orden')->orderBy('id');
    }

    public function lotesSinZona(): HasMany
    {
        return $this->lotes()->whereNull('zona_terreno_id');
    }
}
