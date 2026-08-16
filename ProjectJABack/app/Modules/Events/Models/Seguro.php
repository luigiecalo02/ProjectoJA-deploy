<?php

namespace App\Modules\Events\Models;

use App\Modules\Clubs\Models\Persona;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphMany;

class Seguro extends Model
{
    protected $table = 'seguros';

    public const ESTADO_PENDIENTE = 'pendiente';

    public const ESTADO_ACTIVO = 'activo';

    public const ESTADO_VENCIDO = 'vencido';

    public const ESTADO_ANULADO = 'anulado';

    protected $fillable = [
        'persona_id',
        'tipo_seguro_id',
        'evento_id',
        'inscripcion_id',
        'valor',
        'fecha_inicio',
        'fecha_fin',
        'estado',
        'referencia_pago',
        'fecha_pago',
    ];

    protected function casts(): array
    {
        return [
            'valor' => 'decimal:2',
            'fecha_inicio' => 'date',
            'fecha_fin' => 'date',
            'fecha_pago' => 'datetime',
        ];
    }

    public function persona(): BelongsTo
    {
        return $this->belongsTo(Persona::class, 'persona_id');
    }

    public function tipoSeguro(): BelongsTo
    {
        return $this->belongsTo(TipoSeguro::class, 'tipo_seguro_id');
    }

    public function evento(): BelongsTo
    {
        return $this->belongsTo(Event::class, 'evento_id');
    }

    public function inscripcion(): BelongsTo
    {
        return $this->belongsTo(EventoInscripcion::class, 'inscripcion_id');
    }

    public function pagos(): MorphMany
    {
        return $this->morphMany(EventoPago::class, 'pagable');
    }
}
