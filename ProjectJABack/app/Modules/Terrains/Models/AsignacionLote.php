<?php

namespace App\Modules\Terrains\Models;

use App\Models\User;
use App\Modules\Clubs\Models\Club;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AsignacionLote extends Model
{
    protected $table = 'asignaciones_lotes';

    public const ESTADO_ACTIVA = 'activa';

    public const ESTADO_LIBERADA = 'liberada';

    protected $fillable = [
        'evento_lote_id',
        'club_id',
        'cantidad_personas',
        'observaciones',
        'estado',
        'asignado_por',
    ];

    protected function casts(): array
    {
        return [
            'cantidad_personas' => 'integer',
        ];
    }

    public function eventoLote(): BelongsTo
    {
        return $this->belongsTo(EventoLote::class, 'evento_lote_id');
    }

    public function club(): BelongsTo
    {
        return $this->belongsTo(Club::class, 'club_id');
    }

    public function asignadoPor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'asignado_por');
    }
}
