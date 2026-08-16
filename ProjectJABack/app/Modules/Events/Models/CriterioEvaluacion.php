<?php

namespace App\Modules\Events\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class CriterioEvaluacion extends Model
{
    protected $table = 'criterio_evaluacion';

    protected $fillable = [
        'nombre',
        'descripcion',
        'estado',
        'orden',
    ];

    protected function casts(): array
    {
        return [
            'estado' => 'boolean',
            'orden' => 'integer',
        ];
    }

    public function eventos(): BelongsToMany
    {
        return $this->belongsToMany(Event::class, 'evento_criterio', 'criterio_evaluacion_id', 'evento_id')
            ->withPivot(['puntos', 'orden'])
            ->withTimestamps();
    }
}
