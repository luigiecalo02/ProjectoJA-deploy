<?php

namespace App\Modules\Events\Models;

use App\Models\User;
use App\Modules\Clubs\Models\Persona;
use App\Modules\Organizations\Models\Organizacion;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class EventoCalificacion extends Model
{
    protected $table = 'evento_calificacion';

    protected $fillable = [
        'evento_id',
        'persona_id',
        'organizacion_id',
        'puntaje_obtenido',
        'puesto',
        'observaciones',
        'puesto_entrega',
        'tiempo_entrega',
        'resultado_obtenido',
        'calificado_por',
    ];

    protected function casts(): array
    {
        return [
            'puntaje_obtenido' => 'decimal:2',
            'resultado_obtenido' => 'integer',
        ];
    }

    public function evento(): BelongsTo
    {
        return $this->belongsTo(Event::class, 'evento_id');
    }

    public function persona(): BelongsTo
    {
        return $this->belongsTo(Persona::class, 'persona_id');
    }

    public function organizacion(): BelongsTo
    {
        return $this->belongsTo(Organizacion::class, 'organizacion_id');
    }

    public function calificador(): BelongsTo
    {
        return $this->belongsTo(User::class, 'calificado_por');
    }

    public function detalles(): HasMany
    {
        return $this->hasMany(EventoCalificacionDetalle::class, 'calificacion_id');
    }
}
