<?php

namespace App\Modules\Settings\Models;

use App\Modules\Organizations\Models\Organizacion;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ClubesGananciaEclesiastica extends Model
{
    protected $table = 'clubes_ganancia_eclesiasticas';

    protected $fillable = [
        'organizacion_id',
        'distribucion_id',
        'nombre',
        'diezmo',
        'ofrenda',
        'es_predeterminada',
    ];

    protected function casts(): array
    {
        return [
            'diezmo' => 'decimal:2',
            'ofrenda' => 'decimal:2',
            'es_predeterminada' => 'boolean',
        ];
    }

    public function organizacion(): BelongsTo
    {
        return $this->belongsTo(Organizacion::class, 'organizacion_id');
    }

    public function distribucion(): BelongsTo
    {
        return $this->belongsTo(ClubesGananciaDistribucion::class, 'distribucion_id');
    }
}
