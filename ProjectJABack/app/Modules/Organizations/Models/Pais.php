<?php

namespace App\Modules\Organizations\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Pais extends Model
{
    protected $table = 'pais';

    protected $fillable = [
        'codigo',
        'nombre',
    ];

    public function departamentos(): HasMany
    {
        return $this->hasMany(Departamento::class, 'pais_id');
    }

    public function organizaciones(): HasMany
    {
        return $this->hasMany(Organizacion::class, 'pais_id');
    }
}
