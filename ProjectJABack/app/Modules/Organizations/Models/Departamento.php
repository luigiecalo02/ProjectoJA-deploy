<?php

namespace App\Modules\Organizations\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Departamento extends Model
{
    protected $table = 'departamento';

    protected $fillable = [
        'pais_id',
        'codigo',
        'nombre',
    ];

    public function pais(): BelongsTo
    {
        return $this->belongsTo(Pais::class, 'pais_id');
    }

    public function ciudades(): HasMany
    {
        return $this->hasMany(Ciudad::class, 'departamento_id');
    }

    public function organizaciones(): HasMany
    {
        return $this->hasMany(Organizacion::class, 'departamento_id');
    }
}
