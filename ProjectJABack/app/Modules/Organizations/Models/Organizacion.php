<?php

namespace App\Modules\Organizations\Models;

use App\Modules\Clubs\Models\Club;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Organizacion extends Model
{
    protected $table = 'organizacion';

    public const CREATED_AT = 'fecha_creacion';

    public const UPDATED_AT = 'fecha_actualizacion';

    public const TIPO_UNION = 1;

    public const TIPO_ASOCIACION = 2;

    public const TIPO_DISTRITO = 3;

    public const TIPO_IGLESIA = 4;

    public const TIPO_CLUB = 5;

    /** Tipos hijo de Club (IDs reales se resuelven por catálogo; constantes de apoyo). */
    public const TIPO_AVENTUREROS = 6;

    public const TIPO_CONQUISTADORES = 7;

    public const TIPO_GUIAS_MAYORES = 8;

    protected $fillable = [
        'organizacion_padre_id',
        'tipo_organizacion_id',
        'pais_id',
        'departamento_id',
        'ciudad_id',
        'nombre',
        'codigo',
        'direccion',
        'telefono',
        'correo',
        'estado',
    ];

    protected function casts(): array
    {
        return [
            'estado' => 'boolean',
            'fecha_creacion' => 'datetime',
            'fecha_actualizacion' => 'datetime',
        ];
    }

    public function tipo(): BelongsTo
    {
        return $this->belongsTo(TipoOrganizacion::class, 'tipo_organizacion_id');
    }

    public function padre(): BelongsTo
    {
        return $this->belongsTo(self::class, 'organizacion_padre_id');
    }

    public function hijas(): HasMany
    {
        return $this->hasMany(self::class, 'organizacion_padre_id');
    }

    public function pais(): BelongsTo
    {
        return $this->belongsTo(Pais::class, 'pais_id');
    }

    public function departamento(): BelongsTo
    {
        return $this->belongsTo(Departamento::class, 'departamento_id');
    }

    public function ciudad(): BelongsTo
    {
        return $this->belongsTo(Ciudad::class, 'ciudad_id');
    }

    /**
     * Departamentos cubiertos por la organización (p. ej. Asociación con varios departamentos).
     */
    public function departamentos(): BelongsToMany
    {
        return $this->belongsToMany(
            Departamento::class,
            'organizacion_departamento',
            'organizacion_id',
            'departamento_id',
        )->withTimestamps('created_at', 'updated_at');
    }

    public function personas(): HasMany
    {
        return $this->hasMany(PersonaOrganizacion::class, 'organizacion_id');
    }

    public function club(): HasOne
    {
        return $this->hasOne(Club::class, 'organizacion_id');
    }
}
