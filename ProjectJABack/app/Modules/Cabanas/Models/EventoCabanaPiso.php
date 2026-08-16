<?php

namespace App\Modules\Cabanas\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class EventoCabanaPiso extends Model
{
    protected $table = 'evento_cabana_pisos';

    protected $fillable = [
        'evento_cabana_id', 'cabana_piso_id', 'nombre', 'ancho', 'alto', 'orden',
    ];

    protected function casts(): array
    {
        return ['ancho' => 'integer', 'alto' => 'integer', 'orden' => 'integer'];
    }

    public function eventoCabana(): BelongsTo
    {
        return $this->belongsTo(EventoCabana::class);
    }

    public function cuartos(): HasMany
    {
        return $this->hasMany(EventoCabanaCuarto::class)->orderBy('orden');
    }
}
