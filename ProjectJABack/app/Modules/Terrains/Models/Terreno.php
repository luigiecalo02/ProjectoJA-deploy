<?php

namespace App\Modules\Terrains\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Terreno extends Model
{
    protected $table = 'terrenos';

    public const ESTADO_ACTIVO = 'activo';

    public const ESTADO_INACTIVO = 'inactivo';

    protected $fillable = [
        'nombre',
        'descripcion',
        'latitud',
        'longitud',
        'nivel_zoom',
        'geometria',
        'area_total',
        'perimetro',
        'metros_por_persona',
        'imagen_referencia',
        'estado',
        'created_by',
    ];

    protected function casts(): array
    {
        return [
            'latitud' => 'float',
            'longitud' => 'float',
            'nivel_zoom' => 'integer',
            'geometria' => 'array',
            'area_total' => 'float',
            'perimetro' => 'float',
            'metros_por_persona' => 'float',
        ];
    }

    public function creador(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function configuraciones(): HasMany
    {
        return $this->hasMany(ConfiguracionTerreno::class, 'terreno_id')->orderBy('orden')->orderBy('id');
    }

    public function estructuras(): HasMany
    {
        return $this->hasMany(EstructuraTerreno::class, 'terreno_id')->orderBy('orden')->orderBy('id');
    }

    public function eventosTerrenos(): HasMany
    {
        return $this->hasMany(EventoTerreno::class, 'terreno_id');
    }
}
