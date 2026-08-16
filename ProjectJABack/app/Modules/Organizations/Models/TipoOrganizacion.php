<?php

namespace App\Modules\Organizations\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class TipoOrganizacion extends Model
{
    protected $table = 'tipo_organizacion';

    protected $fillable = [
        'tipo_organizacion_padre_id',
        'nombre',
        'descripcion',
        'estado',
    ];

    protected function casts(): array
    {
        return [
            'estado' => 'boolean',
        ];
    }

    public function padre(): BelongsTo
    {
        return $this->belongsTo(self::class, 'tipo_organizacion_padre_id');
    }

    public function hijos(): HasMany
    {
        return $this->hasMany(self::class, 'tipo_organizacion_padre_id');
    }

    public function organizaciones(): HasMany
    {
        return $this->hasMany(Organizacion::class, 'tipo_organizacion_id');
    }

    public function esRaiz(): bool
    {
        return $this->tipo_organizacion_padre_id === null;
    }
}
