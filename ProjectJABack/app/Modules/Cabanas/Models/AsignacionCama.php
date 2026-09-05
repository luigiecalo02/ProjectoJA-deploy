<?php

namespace App\Modules\Cabanas\Models;

use App\Models\User;
use App\Modules\Events\Models\Event;
use App\Modules\Events\Models\EventoInscripcionPersona;
use App\Modules\Events\Models\EventoServicioReserva;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AsignacionCama extends Model
{
    public const ESTADO_ACTIVA = 'activa';

    public const ESTADO_LIBERADA = 'liberada';

    public const ESTADO_DESPLAZADA = 'desplazada';

    protected $table = 'asignaciones_cama';

    protected $fillable = [
        'evento_id', 'evento_cabana_cama_id', 'inscripcion_persona_id',
        'evento_servicio_reserva_id', 'evento_alojamiento_cupo_id',
        'estado', 'asignado_por', 'liberada_at',
        'snapshot_cabana_nombre', 'snapshot_piso_nombre', 'snapshot_cuarto_nombre',
        'snapshot_cama_codigo', 'snapshot_precio',
    ];

    protected function casts(): array
    {
        return [
            'liberada_at' => 'datetime',
            'snapshot_precio' => 'decimal:2',
        ];
    }

    public function evento(): BelongsTo
    {
        return $this->belongsTo(Event::class, 'evento_id');
    }

    public function cama(): BelongsTo
    {
        return $this->belongsTo(EventoCabanaCama::class, 'evento_cabana_cama_id');
    }

    public function inscripcionPersona(): BelongsTo
    {
        return $this->belongsTo(EventoInscripcionPersona::class);
    }

    public function reserva(): BelongsTo
    {
        return $this->belongsTo(EventoServicioReserva::class, 'evento_servicio_reserva_id');
    }

    public function asignadoPor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'asignado_por');
    }

    public function cupo(): BelongsTo
    {
        return $this->belongsTo(EventoAlojamientoCupo::class, 'evento_alojamiento_cupo_id');
    }
}
