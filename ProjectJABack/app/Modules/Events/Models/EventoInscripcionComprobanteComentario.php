<?php

namespace App\Modules\Events\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class EventoInscripcionComprobanteComentario extends Model
{
    protected $table = 'evento_inscripcion_comprobante_comentarios';

    public const AUTOR_DIRECTOR = 'director';

    public const AUTOR_SUPERVISOR = 'supervisor';

    protected $fillable = [
        'comprobante_id',
        'autor_id',
        'autor_tipo',
        'mensaje',
    ];

    public function comprobante(): BelongsTo
    {
        return $this->belongsTo(EventoInscripcionComprobante::class, 'comprobante_id');
    }

    public function autor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'autor_id');
    }
}
