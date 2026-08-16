<?php

namespace App\Modules\Events\Models;

use App\Modules\Cabanas\Models\AsignacionCama;
use App\Modules\Clubs\Models\Persona;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;

class EventoServicioReserva extends Model
{
    protected $table = 'evento_servicio_reserva';

    public const ESTADO_RESERVADA = 'reservada';

    public const ESTADO_CONFIRMADA = 'confirmada';

    public const ESTADO_CANCELADA = 'cancelada';

    public const ESTADO_USADA = 'usada';

    protected $fillable = [
        'evento_id',
        'evento_producto_servicio_id',
        'persona_id',
        'inscripcion_persona_id',
        'inscripcion_id',
        'precio_unitario',
        'cantidad',
        'valor_total',
        'fecha_inicio',
        'fecha_fin',
        'cantidad_dias',
        'precio_dia',
        'fecha',
        'estado',
    ];

    protected function casts(): array
    {
        return [
            'precio_unitario' => 'decimal:2',
            'valor_total' => 'decimal:2',
            'precio_dia' => 'decimal:2',
            'cantidad' => 'integer',
            'cantidad_dias' => 'integer',
            'fecha_inicio' => 'date',
            'fecha_fin' => 'date',
            'fecha' => 'date',
        ];
    }

    public function evento(): BelongsTo
    {
        return $this->belongsTo(Event::class, 'evento_id');
    }

    public function oferta(): BelongsTo
    {
        return $this->belongsTo(EventoProductoServicio::class, 'evento_producto_servicio_id');
    }

    public function persona(): BelongsTo
    {
        return $this->belongsTo(Persona::class, 'persona_id');
    }

    public function inscripcionPersona(): BelongsTo
    {
        return $this->belongsTo(EventoInscripcionPersona::class, 'inscripcion_persona_id');
    }

    public function inscripcion(): BelongsTo
    {
        return $this->belongsTo(EventoInscripcion::class, 'inscripcion_id');
    }

    public function pagos(): MorphMany
    {
        return $this->morphMany(EventoPago::class, 'pagable');
    }

    public function asignacionesCama(): HasMany
    {
        return $this->hasMany(AsignacionCama::class, 'evento_servicio_reserva_id');
    }
}
