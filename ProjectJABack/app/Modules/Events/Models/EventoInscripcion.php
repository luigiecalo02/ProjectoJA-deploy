<?php

namespace App\Modules\Events\Models;

use App\Models\User;
use App\Modules\Clubs\Models\Persona;
use App\Modules\Organizations\Models\Organizacion;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class EventoInscripcion extends Model
{
    protected $table = 'evento_inscripcion';

    public const ESTADO_PENDIENTE_REVISION = 'pendiente_revision';

    public const ESTADO_EN_REVISION = 'en_revision';

    public const ESTADO_APROBADA = 'aprobada';

    public const ESTADO_NO_APROBADA = 'no_aprobada';

    /** @deprecated use ESTADO_APROBADA */
    public const ESTADO_CONFIRMADA = 'aprobada';

    protected $fillable = [
        'evento_id',
        'tipo',
        'persona_id',
        'organizacion_id',
        'estado',
        'total_declarado',
        'inscrito_por',
        'revisado_por',
        'revisado_at',
        'observacion_revision',
    ];

    protected function casts(): array
    {
        return [
            'total_declarado' => 'decimal:2',
            'revisado_at' => 'datetime',
        ];
    }

    public function evento(): BelongsTo
    {
        return $this->belongsTo(Event::class, 'evento_id');
    }

    public function persona(): BelongsTo
    {
        return $this->belongsTo(Persona::class, 'persona_id');
    }

    public function organizacion(): BelongsTo
    {
        return $this->belongsTo(Organizacion::class, 'organizacion_id');
    }

    public function inscritoPor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'inscrito_por');
    }

    public function revisadoPor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'revisado_por');
    }

    public function pagos(): HasMany
    {
        return $this->hasMany(EventoPago::class, 'inscripcion_id');
    }

    public function personas(): HasMany
    {
        return $this->hasMany(EventoInscripcionPersona::class, 'inscripcion_id');
    }

    public function comprobantes(): HasMany
    {
        return $this->hasMany(EventoInscripcionComprobante::class, 'inscripcion_id');
    }

    public function movimientos(): HasMany
    {
        return $this->hasMany(EventoInscripcionMovimiento::class, 'inscripcion_id');
    }

    /**
     * @return array{total_consignado: float, total_aprobado: float, saldo_por_soportar: float}
     */
    public function resumenComprobantes(): array
    {
        $comprobantes = $this->loadMissing('comprobantes')->comprobantes;
        $totalConsignado = (float) $comprobantes
            ->where('estado', '!=', EventoInscripcionComprobante::ESTADO_RECHAZADO)
            ->sum('valor');
        $totalAprobado = (float) $comprobantes
            ->where('estado', EventoInscripcionComprobante::ESTADO_APROBADO)
            ->sum('valor');

        return [
            'total_consignado' => round($totalConsignado, 2),
            'total_aprobado' => round($totalAprobado, 2),
            'saldo_por_soportar' => round(max(0, (float) ($this->total_declarado ?? 0) - $totalConsignado), 2),
        ];
    }

    public function seguros(): HasMany
    {
        return $this->hasMany(Seguro::class, 'inscripcion_id');
    }

    public function reservas(): HasMany
    {
        return $this->hasMany(EventoServicioReserva::class, 'inscripcion_id');
    }

    public function estaAprobada(): bool
    {
        return $this->estado === self::ESTADO_APROBADA;
    }
}
