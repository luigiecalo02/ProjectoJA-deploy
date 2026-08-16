<?php

namespace App\Modules\Events\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class EventoInscripcionMovimiento extends Model
{
    protected $table = 'evento_inscripcion_movimiento';

    public const TIPO_INICIAL = 'inicial';

    public const TIPO_MODIFICACION = 'modificacion';

    protected $fillable = [
        'inscripcion_id',
        'numero',
        'tipo',
        'total_anterior',
        'total_nuevo',
        'valor_diferencia',
        'snapshot',
        'cambios',
        'creado_por',
    ];

    protected function casts(): array
    {
        return [
            'total_anterior' => 'decimal:2',
            'total_nuevo' => 'decimal:2',
            'valor_diferencia' => 'decimal:2',
            'snapshot' => 'array',
            'cambios' => 'array',
        ];
    }

    public function inscripcion(): BelongsTo
    {
        return $this->belongsTo(EventoInscripcion::class, 'inscripcion_id');
    }

    public function comprobantes(): HasMany
    {
        return $this->hasMany(EventoInscripcionComprobante::class, 'movimiento_id');
    }

    public function creadoPor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'creado_por');
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
        $valorACubrir = max(0, (float) $this->valor_diferencia);

        return [
            'total_consignado' => round($totalConsignado, 2),
            'total_aprobado' => round($totalAprobado, 2),
            'saldo_por_soportar' => round(max(0, $valorACubrir - $totalConsignado), 2),
        ];
    }
}
