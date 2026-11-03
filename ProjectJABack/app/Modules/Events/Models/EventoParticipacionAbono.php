<?php

namespace App\Modules\Events\Models;

use App\Models\User;
use App\Modules\Clubs\Models\Persona;
use App\Modules\Organizations\Models\Organizacion;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Schema;

class EventoParticipacionAbono extends Model
{
    public const ESTADO_PENDIENTE = 'pendiente';

    public const ESTADO_APROBADO = 'aprobado';

    public const ESTADO_RECHAZADO = 'rechazado';

    public const ESTADO_ELIMINADO = 'eliminado';

    public const TIPO_ACTIVIDAD = 'actividad';

    public const TIPO_LIBRE = 'libre';

    protected $table = 'evento_participacion_abono';

    protected $fillable = [
        'evento_id',
        'organizacion_id',
        'persona_id',
        'producto_servicio_id',
        'tipo',
        'monto',
        'nota',
        'estado',
        'comprobante_path',
        'registrado_por',
    ];

    protected function casts(): array
    {
        return [
            'monto' => 'decimal:2',
        ];
    }

    public function evento(): BelongsTo
    {
        return $this->belongsTo(Event::class, 'evento_id');
    }

    public function organizacion(): BelongsTo
    {
        return $this->belongsTo(Organizacion::class, 'organizacion_id');
    }

    public function persona(): BelongsTo
    {
        return $this->belongsTo(Persona::class, 'persona_id');
    }

    public function producto(): BelongsTo
    {
        return $this->belongsTo(ProductoServicio::class, 'producto_servicio_id');
    }

    public function registrador(): BelongsTo
    {
        return $this->belongsTo(User::class, 'registrado_por');
    }

    public static function hasTipoColumn(): bool
    {
        static $ready = null;
        if ($ready === null) {
            $ready = Schema::hasColumn((new static)->getTable(), 'tipo');
        }

        return $ready;
    }

    public function isLibre(): bool
    {
        if (! self::hasTipoColumn()) {
            return false;
        }

        return ($this->tipo ?: self::TIPO_ACTIVIDAD) === self::TIPO_LIBRE;
    }

    public function montoContable(): float
    {
        if ($this->isLibre()) {
            return 0.0;
        }

        $estado = $this->estado ?: self::ESTADO_APROBADO;

        return $estado === self::ESTADO_APROBADO ? (float) $this->monto : 0.0;
    }

    public function isEliminado(): bool
    {
        return ($this->estado ?: self::ESTADO_APROBADO) === self::ESTADO_ELIMINADO;
    }

    public function visiblePara(?User $actor): bool
    {
        if (! $this->isEliminado()) {
            return true;
        }

        return $actor?->isSuperAdmin() === true;
    }
}
