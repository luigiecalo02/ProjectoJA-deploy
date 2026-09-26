<?php

namespace App\Modules\Events\Models;

use App\Modules\Clubs\Models\Persona;
use App\Modules\Organizations\Models\Organizacion;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class EventoParticipacion extends Model
{
    protected $table = 'evento_participacion';

    protected $fillable = [
        'evento_id',
        'organizacion_id',
        'persona_id',
        'participa',
    ];

    protected function casts(): array
    {
        return [
            'participa' => 'boolean',
        ];
    }

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

    public function ventas(): HasMany
    {
        return $this->hasMany(EventoParticipacionVenta::class, 'evento_participacion_id');
    }
}
