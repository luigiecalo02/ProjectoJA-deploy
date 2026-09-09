<?php

namespace App\Modules\Events\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class EventoCriterio extends Model
{
    protected $table = 'evento_criterio';

    protected $fillable = [
        'evento_id',
        'criterio_evaluacion_id',
        'puntos',
        'orden',
        'juez_id',
    ];

    protected function casts(): array
    {
        return [
            'puntos' => 'decimal:2',
            'orden' => 'integer',
            'juez_id' => 'integer',
        ];
    }

    public function evento(): BelongsTo
    {
        return $this->belongsTo(Event::class, 'evento_id');
    }

    public function criterio(): BelongsTo
    {
        return $this->belongsTo(CriterioEvaluacion::class, 'criterio_evaluacion_id');
    }

    public function juez(): BelongsTo
    {
        return $this->belongsTo(User::class, 'juez_id');
    }
}
