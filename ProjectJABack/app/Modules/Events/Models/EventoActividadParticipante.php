<?php

namespace App\Modules\Events\Models;

use App\Models\User;
use App\Modules\Clubs\Models\Persona;
use App\Modules\Organizations\Models\Organizacion;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class EventoActividadParticipante extends Model
{
    protected $table = 'evento_actividad_participante';

    protected $fillable = [
        'evento_id',
        'organizacion_id',
        'persona_id',
        'inscrito_por',
    ];

    public function evento(): BelongsTo
    {
        return $this->belongsTo(Event::class, 'evento_id');
    }

    public function organizacion(): BelongsTo
    {
        return $this->belongsTo(Organizacion::class, 'organizacion_id');
    }

    public function persona(): BelongsTo
    {
        return $this->belongsTo(Persona::class, 'persona_id');
    }

    public function inscritoPor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'inscrito_por');
    }
}
