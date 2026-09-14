<?php

namespace App\Modules\Events\Models;

use App\Models\User;
use App\Modules\Clubs\Models\Persona;
use App\Modules\Organizations\Models\Organizacion;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class EventoAsistencia extends Model
{
    public const ESTADO_PRESENTE = 'presente';

    public const ESTADO_AUSENTE = 'ausente';

    public const ESTADO_JUSTIFICADO = 'justificado';

    public const ESTADOS = [
        self::ESTADO_PRESENTE,
        self::ESTADO_AUSENTE,
        self::ESTADO_JUSTIFICADO,
    ];

    protected $fillable = [
        'evento_id',
        'organizacion_id',
        'persona_id',
        'estado',
        'notas',
        'registrado_por',
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

    public function registradoPor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'registrado_por');
    }
}
