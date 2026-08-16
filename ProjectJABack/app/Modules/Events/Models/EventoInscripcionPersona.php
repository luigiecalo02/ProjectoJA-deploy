<?php

namespace App\Modules\Events\Models;

use App\Modules\Cabanas\Models\AsignacionCama;
use App\Modules\Clubs\Models\Persona;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;

class EventoInscripcionPersona extends Model
{
    protected $table = 'evento_inscripcion_persona';

    public const TIPO_MIEMBRO = 'miembro';

    public const TIPO_DIRECTIVA = 'directiva';

    public const TIPO_ACOMPANANTE = 'acompanante';

    public const TIPO_ACOMPANANTE_MENOR = 'acompanante_menor';

    public const TIPO_VISITANTE_PASADIA = 'visitante_pasadia';

    public const CARGO_DIRECTOR = 'director';

    public const CARGO_SUBDIRECTOR = 'subdirector';

    public const CARGO_SECRETARIO = 'secretario';

    public const CARGO_TESORERO = 'tesorero';

    public const CARGOS_DIRECTIVA = [
        self::CARGO_DIRECTOR,
        self::CARGO_SUBDIRECTOR,
        self::CARGO_SECRETARIO,
        self::CARGO_TESORERO,
    ];

    public const ESTADO_PENDIENTE = 'pendiente';

    public const ESTADO_CONFIRMADA = 'confirmada';

    public const ESTADO_CANCELADA = 'cancelada';

    protected $fillable = [
        'inscripcion_id',
        'persona_id',
        'tipo',
        'cargo_directiva',
        'referencia_cliente',
        'nombre_snapshot',
        'identificacion_snapshot',
        'fecha_nacimiento_snapshot',
        'parentesco',
        'descuento_codigo',
        'descuento_nombre',
        'descuento_porcentaje',
        'valor_base',
        'valor_descuento',
        'valor_inscripcion',
        'valor_seguro',
        'estado',
    ];

    protected function casts(): array
    {
        return [
            'fecha_nacimiento_snapshot' => 'date',
            'descuento_porcentaje' => 'decimal:2',
            'valor_base' => 'decimal:2',
            'valor_descuento' => 'decimal:2',
            'valor_inscripcion' => 'decimal:2',
            'valor_seguro' => 'decimal:2',
        ];
    }

    public function inscripcion(): BelongsTo
    {
        return $this->belongsTo(EventoInscripcion::class, 'inscripcion_id');
    }

    public function persona(): BelongsTo
    {
        return $this->belongsTo(Persona::class, 'persona_id');
    }

    public function pagos(): MorphMany
    {
        return $this->morphMany(EventoPago::class, 'pagable');
    }

    public function reservas(): HasMany
    {
        return $this->hasMany(EventoServicioReserva::class, 'inscripcion_persona_id');
    }

    public function asignacionesCama(): HasMany
    {
        return $this->hasMany(AsignacionCama::class, 'inscripcion_persona_id');
    }
}
