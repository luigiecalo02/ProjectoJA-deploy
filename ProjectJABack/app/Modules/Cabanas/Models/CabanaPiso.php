<?php

namespace App\Modules\Cabanas\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class CabanaPiso extends Model
{
    protected $table = 'cabana_pisos';

    protected $fillable = ['cabana_id', 'nombre', 'ancho', 'alto', 'orden'];

    protected function casts(): array
    {
        return ['ancho' => 'integer', 'alto' => 'integer', 'orden' => 'integer'];
    }

    public function cabana(): BelongsTo
    {
        return $this->belongsTo(Cabana::class);
    }

    public function cuartos(): HasMany
    {
        return $this->hasMany(CabanaCuarto::class)->orderBy('orden');
    }
}
