<?php

namespace App\Modules\Events\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class EventoCalificacionDetalle extends Model
{
    protected $table = 'evento_calificacion_detalle';

    protected $fillable = [
        'calificacion_id',
        'criterio_evaluacion_id',
        'puntos',
    ];

    protected function casts(): array
    {
        return [
            'puntos' => 'decimal:2',
        ];
    }

    public function calificacion(): BelongsTo
    {
        return $this->belongsTo(EventoCalificacion::class, 'calificacion_id');
    }

    public function criterio(): BelongsTo
    {
        return $this->belongsTo(CriterioEvaluacion::class, 'criterio_evaluacion_id');
    }
}
