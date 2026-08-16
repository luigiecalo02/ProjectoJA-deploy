<?php

namespace App\Modules\Terrains\Models;

use App\Modules\Events\Models\Event;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class EventoTerreno extends Model
{
    protected $table = 'eventos_terrenos';

    protected $fillable = [
        'evento_id',
        'terreno_id',
        'configuracion_terreno_id',
        'descripcion',
        'estado',
    ];

    public function evento(): BelongsTo
    {
        return $this->belongsTo(Event::class, 'evento_id');
    }

    public function terreno(): BelongsTo
    {
        return $this->belongsTo(Terreno::class, 'terreno_id');
    }

    public function configuracion(): BelongsTo
    {
        return $this->belongsTo(ConfiguracionTerreno::class, 'configuracion_terreno_id');
    }

    public function zonas(): HasMany
    {
        return $this->hasMany(EventoZona::class, 'evento_terreno_id')->orderBy('orden')->orderBy('id');
    }

    public function lotes(): HasMany
    {
        return $this->hasMany(EventoLote::class, 'evento_terreno_id')->orderBy('orden')->orderBy('id');
    }

    public function lotesSinZona(): HasMany
    {
        return $this->lotes()->whereNull('evento_zona_id');
    }

    public function estructuras(): HasMany
    {
        return $this->hasMany(EventoEstructura::class, 'evento_terreno_id')->orderBy('orden')->orderBy('id');
    }
}
