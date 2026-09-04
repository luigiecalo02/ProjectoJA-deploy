<?php

namespace App\Modules\Events\Models;

use App\Models\User;
use App\Modules\Events\Services\EventVisibilityService;
use App\Modules\Organizations\Models\Organizacion;
use App\Modules\Lugares\Models\Lugar;
use App\Modules\Settings\Models\CuentaBancaria;
use App\Modules\Organizations\Models\TipoOrganizacion;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Collection;

class Event extends Model
{
    use SoftDeletes;

    public const ESTADO_BORRADOR = 'borrador';

    public const ESTADO_PUBLICADO = 'publicado';

    public const ESTADO_EN_PROCESO = 'en_proceso';

    public const ESTADO_CERRADO = 'cerrado';

    public const ESTADO_CANCELADO = 'cancelado';

    public const VISIBILIDAD_PUBLICO = 'publico';

    public const VISIBILIDAD_PRIVADO = 'privado';

    public const VISIBILIDAD_ORGANIZACION = 'organizacion';

    protected $fillable = [
        'evento_padre_id',
        'orden',
        'organizacion_id',
        'tipo_evento_id',
        'categoria_subevento_id',
        'categoria_ids',
        'criterio_disponible_ids',
        'name',
        'descripcion',
        'reglas',
        'lugar',
        'lugar_id',
        'usar_lotes',
        'usar_cabanas',
        'latitud',
        'longitud',
        'image_url',
        'banner_url',
        'starts_at',
        'ends_at',
        'created_by',
        'is_active',
        'estado',
        'visibilidad',
        'es_en_sitio',
        'es_calificable',
        'tiene_subeventos',
        'puntaje_maximo',
        'puntaje_desde_hijos',
        'puntaje_por_participar',
        'tiempo_estimado_minutos',
        'requiere_puesto_entrega',
        'requiere_tiempo_entrega',
        'resultado_esperado',
        'participantes_min',
        'participantes_max',
        'permite_inscribir_no_participantes',
        'participantes_genero',
        'participantes_min_m',
        'participantes_max_m',
        'participantes_min_f',
        'participantes_max_f',
        'equipos_org_min',
        'equipos_org_max',
        'es_conjunto',
        'nivel_conjunto',
        'maneja_fecha_fin',
        'maneja_penalizaciones',
        'puntos_penalizacion',
        'reglas_penalizacion',
        'requiere_evidencia',
        'tipos_evidencia',
        'requiere_pago',
        'precio',
        'precio_fuera_tiempo',
        'precio_acompanante',
        'precio_acompanante_fuera_tiempo',
        'precio_acompanante_menor',
        'precio_acompanante_menor_fuera_tiempo',
        'precio_directiva',
        'precio_directiva_fuera_tiempo',
        'descuentos_directiva',
        'fecha_limite_pago',
        'metodo_pago',
        'cuenta_bancaria_id',
        'requiere_seguro',
        'tipo_seguro_id',
        'seguro_valor',
        'seguro_fecha_inicio',
        'seguro_fecha_fin',
        'cupo_minimo',
        'cupo_maximo',
        'cupo_ilimitado',
        'cupo_max_organizacion',
        'cupo_max_club',
        'cupo_max_iglesia',
        'permite_inscripcion_individual',
        'permite_inscripcion_organizacion',
        'permite_inscripcion_club',
        'permite_inscripcion_iglesia',
        'fecha_limite_inscripcion',
        'puntos_inscripcion_a_tiempo',
        'puntos_inscripcion_fuera_tiempo',
    ];

    protected function casts(): array
    {
        return [
            'starts_at' => 'datetime',
            'ends_at' => 'datetime',
            'fecha_limite_pago' => 'datetime',
            'fecha_limite_inscripcion' => 'datetime',
            'is_active' => 'boolean',
            'es_en_sitio' => 'boolean',
            'usar_lotes' => 'boolean',
            'usar_cabanas' => 'boolean',
            'es_calificable' => 'boolean',
            'tiene_subeventos' => 'boolean',
            'puntaje_desde_hijos' => 'boolean',
            'puntaje_por_participar' => 'boolean',
            'requiere_pago' => 'boolean',
            'requiere_seguro' => 'boolean',
            'cupo_ilimitado' => 'boolean',
            'permite_inscripcion_individual' => 'boolean',
            'permite_inscripcion_organizacion' => 'boolean',
            'permite_inscripcion_club' => 'boolean',
            'permite_inscripcion_iglesia' => 'boolean',
            'latitud' => 'decimal:7',
            'longitud' => 'decimal:7',
            'puntaje_maximo' => 'decimal:2',
            'puntos_inscripcion_a_tiempo' => 'decimal:2',
            'puntos_inscripcion_fuera_tiempo' => 'decimal:2',
            'tiempo_estimado_minutos' => 'integer',
            'requiere_puesto_entrega' => 'boolean',
            'requiere_tiempo_entrega' => 'boolean',
            'resultado_esperado' => 'integer',
            'participantes_min' => 'integer',
            'participantes_max' => 'integer',
            'permite_inscribir_no_participantes' => 'boolean',
            'participantes_min_m' => 'integer',
            'participantes_max_m' => 'integer',
            'participantes_min_f' => 'integer',
            'participantes_max_f' => 'integer',
            'equipos_org_min' => 'integer',
            'equipos_org_max' => 'integer',
            'es_conjunto' => 'boolean',
            'maneja_fecha_fin' => 'boolean',
            'maneja_penalizaciones' => 'boolean',
            'puntos_penalizacion' => 'decimal:2',
            'requiere_evidencia' => 'boolean',
            'tipos_evidencia' => 'array',
            'categoria_ids' => 'array',
            'criterio_disponible_ids' => 'array',
            'orden' => 'integer',
            'precio' => 'decimal:2',
            'precio_fuera_tiempo' => 'decimal:2',
            'precio_acompanante' => 'decimal:2',
            'precio_acompanante_fuera_tiempo' => 'decimal:2',
            'precio_acompanante_menor' => 'decimal:2',
            'precio_acompanante_menor_fuera_tiempo' => 'decimal:2',
            'precio_directiva' => 'decimal:2',
            'precio_directiva_fuera_tiempo' => 'decimal:2',
            'descuentos_directiva' => 'array',
            'seguro_valor' => 'decimal:2',
            'seguro_fecha_inicio' => 'date',
            'seguro_fecha_fin' => 'date',
        ];
    }

    /**
     * Descuentos sugeridos para cargos de directiva sobre la inscripción.
     *
     * @return list<array{codigo: string, nombre: string, porcentaje: float}>
     */
    public static function defaultDescuentosDirectiva(): array
    {
        return [
            ['codigo' => 'director', 'nombre' => 'Director', 'porcentaje' => 100],
            ['codigo' => 'economia', 'nombre' => 'Economía', 'porcentaje' => 50],
            ['codigo' => 'hermano_2', 'nombre' => 'Hermano 2', 'porcentaje' => 25],
            ['codigo' => 'hermano_3', 'nombre' => 'Hermano 3', 'porcentaje' => 33.3],
            ['codigo' => 'hermano_4', 'nombre' => 'Hermano 4', 'porcentaje' => 37],
        ];
    }

    /**
     * Calcula el valor a cobrar de inscripción/directiva aplicando descuento por cargo.
     * Base: precio_directiva si está definido; si no, precio de inscripción.
     */
    public function valorConDescuentoDirectiva(?string $codigoCargo): float
    {
        $fueraDeTiempo = $this->estaFueraDeFechaLimiteInscripcion();
        $base = (float) (
            ($fueraDeTiempo ? $this->precio_directiva_fuera_tiempo : null)
                ?? $this->precio_directiva
                ?? ($fueraDeTiempo ? $this->precio_fuera_tiempo : null)
                ?? $this->precio
                ?? 0
        );

        if (! $codigoCargo) {
            return round($base, 2);
        }

        $porcentaje = 0.0;
        foreach ($this->descuentos_directiva ?? [] as $row) {
            if (! is_array($row)) {
                continue;
            }
            if (($row['codigo'] ?? null) === $codigoCargo) {
                $porcentaje = (float) ($row['porcentaje'] ?? 0);
                break;
            }
        }

        $porcentaje = max(0, min(100, $porcentaje));

        return round($base * (1 - ($porcentaje / 100)), 2);
    }

    public function estaFueraDeFechaLimiteInscripcion(?CarbonInterface $fecha = null): bool
    {
        return $this->fecha_limite_inscripcion !== null
            && ($fecha ?? now())->greaterThan($this->fecha_limite_inscripcion);
    }

    public function tipoSeguro(): BelongsTo
    {
        return $this->belongsTo(TipoSeguro::class, 'tipo_seguro_id');
    }

    public function productosServicio(): HasMany
    {
        return $this->hasMany(EventoProductoServicio::class, 'evento_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function jueces(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'evento_juez', 'evento_id', 'user_id')
            ->withTimestamps();
    }

    public function supervisores(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'evento_supervisor', 'evento_id', 'user_id')
            ->withTimestamps();
    }

    /**
     * Jueces propios del nodo (sin herencia).
     *
     * @return Collection<int, User>
     */
    public function ownJueces()
    {
        return $this->relationLoaded('jueces')
            ? $this->jueces
            : $this->jueces()->get(['users.id', 'users.name', 'users.email']);
    }

    /**
     * Supervisores propios del nodo (sin herencia).
     *
     * @return Collection<int, User>
     */
    public function ownSupervisores()
    {
        return $this->relationLoaded('supervisores')
            ? $this->supervisores
            : $this->supervisores()->get(['users.id', 'users.name', 'users.email']);
    }

    /**
     * Si el hijo no tiene jueces propios, hereda los del padre (efectivos).
     *
     * @param  Collection<int, User>|null  $inheritedFromParent
     * @return array{0: Collection<int, User>, 1: bool}
     */
    public function resolveEffectiveJueces($inheritedFromParent = null): array
    {
        $own = $this->ownJueces();
        if ($own->isNotEmpty()) {
            return [$own, false];
        }

        if ($inheritedFromParent !== null) {
            return [$inheritedFromParent, $inheritedFromParent->isNotEmpty()];
        }

        if (! $this->evento_padre_id) {
            return [$own, false];
        }

        $padre = $this->relationLoaded('padre') ? $this->padre : null;
        if (! $padre) {
            $padre = self::query()
                ->with('jueces:id,name,email')
                ->find($this->evento_padre_id);
        }

        if (! $padre) {
            return [$own, false];
        }

        [$effective] = $padre->resolveEffectiveJueces();

        return [$effective, $effective->isNotEmpty()];
    }

    /**
     * Si el hijo no tiene supervisores propios, hereda los del padre (efectivos).
     *
     * @param  Collection<int, User>|null  $inheritedFromParent
     * @return array{0: Collection<int, User>, 1: bool}
     */
    public function resolveEffectiveSupervisores($inheritedFromParent = null): array
    {
        $own = $this->ownSupervisores();
        if ($own->isNotEmpty()) {
            return [$own, false];
        }

        if ($inheritedFromParent !== null) {
            return [$inheritedFromParent, $inheritedFromParent->isNotEmpty()];
        }

        if (! $this->evento_padre_id) {
            return [$own, false];
        }

        $padre = $this->relationLoaded('padre') ? $this->padre : null;
        if (! $padre) {
            $padre = self::query()
                ->with('supervisores:id,name,email')
                ->find($this->evento_padre_id);
        }

        if (! $padre) {
            return [$own, false];
        }

        [$effective] = $padre->resolveEffectiveSupervisores();

        return [$effective, $effective->isNotEmpty()];
    }

    public function padre(): BelongsTo
    {
        return $this->belongsTo(self::class, 'evento_padre_id');
    }

    public function hijos(): HasMany
    {
        return $this->hasMany(self::class, 'evento_padre_id')->orderBy('orden')->orderBy('id');
    }

    public function organizacion(): BelongsTo
    {
        return $this->belongsTo(Organizacion::class, 'organizacion_id');
    }

    public function tipoEvento(): BelongsTo
    {
        return $this->belongsTo(TipoEvento::class, 'tipo_evento_id');
    }

    public function cuentaBancaria(): BelongsTo
    {
        return $this->belongsTo(CuentaBancaria::class, 'cuenta_bancaria_id');
    }

    public function catalogLugar(): BelongsTo
    {
        return $this->belongsTo(Lugar::class, 'lugar_id');
    }

    public function categoriaSubevento(): BelongsTo
    {
        return $this->belongsTo(CategoriaSubevento::class, 'categoria_subevento_id');
    }

    public function organizaciones(): BelongsToMany
    {
        return $this->belongsToMany(
            Organizacion::class,
            'evento_organizacion',
            'evento_id',
            'organizacion_id'
        )->withTimestamps();
    }

    public function tiposOrganizacion(): BelongsToMany
    {
        return $this->belongsToMany(
            TipoOrganizacion::class,
            'evento_tipo_organizacion',
            'evento_id',
            'tipo_organizacion_id'
        )->withTimestamps();
    }

    public function archivos(): HasMany
    {
        return $this->hasMany(EventoArchivo::class, 'evento_id');
    }

    public function inscripciones(): HasMany
    {
        return $this->hasMany(EventoInscripcion::class, 'evento_id');
    }

    public function calificaciones(): HasMany
    {
        return $this->hasMany(EventoCalificacion::class, 'evento_id');
    }

    public function evidencias(): HasMany
    {
        return $this->hasMany(EventoEvidencia::class, 'evento_id');
    }

    public function criterios(): BelongsToMany
    {
        return $this->belongsToMany(
            CriterioEvaluacion::class,
            'evento_criterio',
            'evento_id',
            'criterio_evaluacion_id'
        )->withPivot(['id', 'puntos', 'orden'])
            ->withTimestamps()
            ->orderByPivot('orden');
    }

    public function eventoCriterios(): HasMany
    {
        return $this->hasMany(EventoCriterio::class, 'evento_id')->orderBy('orden');
    }

    public function isLive(): bool
    {
        return in_array($this->estado, [self::ESTADO_PUBLICADO, self::ESTADO_EN_PROCESO], true);
    }

    public function locksDirectorModifications(): bool
    {
        return in_array($this->estado, [
            self::ESTADO_EN_PROCESO,
            self::ESTADO_CERRADO,
            self::ESTADO_CANCELADO,
        ], true);
    }

    public function isVisibleTo(User $user): bool
    {
        return app(EventVisibilityService::class)->isVisibleTo($this, $user);
    }

    public function scopeVisibleTo(Builder $query, User $user): Builder
    {
        return app(EventVisibilityService::class)->applyVisibleScope($query, $user);
    }

    /**
     * @return list<int>
     */
    public function intIdList(string $attribute): array
    {
        return self::normalizeIntIdList($this->getAttribute($attribute));
    }

    /**
     * @return list<int>
     */
    public static function normalizeIntIdList(mixed $value): array
    {
        return array_values(array_map('intval', self::normalizeJsonArray($value)));
    }

    /**
     * @return list<mixed>
     */
    public static function normalizeJsonArray(mixed $value): array
    {
        if ($value === null || $value === '') {
            return [];
        }

        if (is_array($value)) {
            return array_values($value);
        }

        if (! is_string($value)) {
            return [];
        }

        $decoded = json_decode($value, true);
        if (is_array($decoded)) {
            return array_values($decoded);
        }

        if (is_string($decoded)) {
            $again = json_decode($decoded, true);
            if (is_array($again)) {
                return array_values($again);
            }
        }

        return [];
    }
}
