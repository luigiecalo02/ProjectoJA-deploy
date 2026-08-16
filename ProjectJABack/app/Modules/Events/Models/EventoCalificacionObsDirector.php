<?php

namespace App\Modules\Events\Models;

use App\Models\User;
use App\Modules\Organizations\Models\Organizacion;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class EventoCalificacionObsDirector extends Model
{
    protected $table = 'evento_calificacion_obs_director';

    protected $fillable = [
        'evento_id',
        'organizacion_id',
        'observaciones',
        'creado_por',
    ];

    public function evento(): BelongsTo
    {
        return $this->belongsTo(Event::class, 'evento_id');
    }

    public function organizacion(): BelongsTo
    {
        return $this->belongsTo(Organizacion::class, 'organizacion_id');
    }

    public function autor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'creado_por');
    }
}
