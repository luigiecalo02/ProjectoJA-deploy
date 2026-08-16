<?php

namespace App\Modules\Events\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

class EventoPago extends Model
{
    protected $table = 'evento_pago';

    public const ESTADO_PENDIENTE = 'pendiente';

    public const ESTADO_PAGADO = 'pagado';

    public const ESTADO_ANULADO = 'anulado';

    protected $fillable = [
        'inscripcion_id',
        'pagable_type',
        'pagable_id',
        'monto',
        'moneda',
        'metodo',
        'estado',
        'fecha_limite',
        'pagado_at',
        'referencia',
    ];

    protected function casts(): array
    {
        return [
            'monto' => 'decimal:2',
            'fecha_limite' => 'datetime',
            'pagado_at' => 'datetime',
        ];
    }

    public function inscripcion(): BelongsTo
    {
        return $this->belongsTo(EventoInscripcion::class, 'inscripcion_id');
    }

    public function pagable(): MorphTo
    {
        return $this->morphTo();
    }
}
