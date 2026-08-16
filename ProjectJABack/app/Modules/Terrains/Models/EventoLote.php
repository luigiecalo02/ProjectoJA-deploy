<?php

namespace App\Modules\Terrains\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class EventoLote extends Model
{
    protected $table = 'eventos_lotes';

    public const ESTADO_DISPONIBLE = 'disponible';

    public const ESTADO_ASIGNADO = 'asignado';

    public const ESTADO_RESERVADO = 'reservado';

    public const ESTADO_NO_DISPONIBLE = 'no_disponible';

    protected $fillable = [
        'evento_terreno_id',
        'evento_zona_id',
        'lote_terreno_id',
        'codigo',
        'nombre',
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

    public function eventoTerreno(): BelongsTo
    {
        return $this->belongsTo(EventoTerreno::class, 'evento_terreno_id');
    }

    public function eventoZona(): BelongsTo
    {
        return $this->belongsTo(EventoZona::class, 'evento_zona_id');
    }

    public function loteBase(): BelongsTo
    {
        return $this->belongsTo(LoteTerreno::class, 'lote_terreno_id');
    }

    public function asignaciones(): HasMany
    {
        return $this->hasMany(AsignacionLote::class, 'evento_lote_id');
    }

    public function asignacionActiva(): ?AsignacionLote
    {
        return $this->asignaciones()->where('estado', AsignacionLote::ESTADO_ACTIVA)->first();
    }
}
