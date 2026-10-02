<?php

namespace App\Modules\Settings\Models;

use App\Modules\Organizations\Models\Organizacion;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ClubesGananciaDistribucion extends Model
{
    protected $table = 'clubes_ganancia_distribuciones';

    protected $fillable = [
        'organizacion_id',
        'nombre',
        'club',
        'miembros',
        'extras',
        'es_predeterminada',
    ];

    protected function casts(): array
    {
        return [
            'club' => 'decimal:2',
            'miembros' => 'decimal:2',
            'extras' => 'decimal:2',
            'es_predeterminada' => 'boolean',
        ];
    }

    public function organizacion(): BelongsTo
    {
        return $this->belongsTo(Organizacion::class, 'organizacion_id');
    }

    public function eclesiasticas(): HasMany
    {
        return $this->hasMany(ClubesGananciaEclesiastica::class, 'distribucion_id');
    }
}
