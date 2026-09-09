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

    /** IDs históricos (ya no son tipos de catálogo; se conservan para audiencia de eventos). */
    public const TIPO_AVENTUREROS = 6;

    public const TIPO_CONQUISTADORES = 7;

    public const TIPO_GUIAS_MAYORES = 8;

    public const TIPO_ZONA = 9;

    /**
     * Tipos que dejaron de ser organizaciones (ahora son ministerio del Club).
     *
     * @return list<int>
     */
    public static function tiposRetiradosHijoClub(): array
    {
        return [
            self::TIPO_AVENTUREROS,
            self::TIPO_CONQUISTADORES,
            self::TIPO_GUIAS_MAYORES,
        ];
    }

    /**
     * Unión → Asociación → Zona → Distrito → Iglesia → Club
     *
     * @return list<int>
     */
    public static function ordenJerarquia(): array
    {
        return [
            self::TIPO_UNION,
            self::TIPO_ASOCIACION,
            self::TIPO_ZONA,
            self::TIPO_DISTRITO,
            self::TIPO_IGLESIA,
            self::TIPO_CLUB,
            ...self::tiposRetiradosHijoClub(),
        ];
    }

    public static function rangoJerarquia(int $tipoId): int
    {
        $pos = array_search($tipoId, self::ordenJerarquia(), true);

        return $pos === false ? 99 : $pos;
    }

    public const APROBACION_PENDIENTE = 'pendiente';

    public const APROBACION_APROBADA = 'aprobada';

    public const APROBACION_RECHAZADA = 'rechazada';

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
        'estado_aprobacion',
        'revision_observacion',
        'revisado_por',
        'revisado_en',
    ];

    protected function casts(): array
    {
        return [
            'estado' => 'boolean',
            'revisado_en' => 'datetime',
            'fecha_creacion' => 'datetime',
            'fecha_actualizacion' => 'datetime',
        ];
    }

    public function isAprobada(): bool
    {
        return $this->estado_aprobacion === self::APROBACION_APROBADA;
    }

    public function isPendiente(): bool
    {
        return $this->estado_aprobacion === self::APROBACION_PENDIENTE;
    }

    /**
     * @return list<int>
     */
    public function coberturaDepartamentoIds(): array
    {
        $this->loadMissing('departamentos:id');
        $ids = $this->departamentos->pluck('id')->map(fn ($id) => (int) $id)->all();
        if ($ids === [] && $this->departamento_id) {
            $ids = [(int) $this->departamento_id];
        }
        if ($ids === [] && $this->organizacion_padre_id) {
            $padre = $this->padre ?? self::query()->find($this->organizacion_padre_id);
            if ($padre) {
                return $padre->coberturaDepartamentoIds();
            }
        }

        return array_values(array_unique($ids));
    }

    /**
     * @return list<int>
     */
    public function coberturaCiudadIds(): array
    {
        $this->loadMissing('ciudades:id');
        $ids = $this->ciudades->pluck('id')->map(fn ($id) => (int) $id)->all();
        if ($ids === [] && $this->ciudad_id) {
            $ids = [(int) $this->ciudad_id];
        }
        if ($ids === [] && $this->organizacion_padre_id) {
            $padre = $this->padre ?? self::query()->find($this->organizacion_padre_id);
            if ($padre) {
                return $padre->coberturaCiudadIds();
            }
        }

        return array_values(array_unique($ids));
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

    public function ciudades(): BelongsToMany
    {
        return $this->belongsToMany(
            Ciudad::class,
            'organizacion_ciudad',
            'organizacion_id',
            'ciudad_id',
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
