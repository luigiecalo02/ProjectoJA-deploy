<?php

namespace App\Modules\Settings\Models;

use App\Models\User;
use App\Modules\Events\Models\Event;
use App\Modules\Organizations\Models\Organizacion;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ClubesCajaMovimiento extends Model
{
    public const TIPO_INGRESO = 'ingreso';

    public const TIPO_EGRESO = 'egreso';

    public const ORIGEN_ABONO = 'abono';

    public const ORIGEN_MANUAL = 'manual';

    public const ORIGEN_AJUSTE = 'ajuste';

    public const ORIGEN_GASTO = 'gasto';

    protected $table = 'clubes_caja_movimientos';

    protected $fillable = [
        'organizacion_id',
        'cuenta_id',
        'cuenta_bancaria_id',
        'tipo',
        'origen',
        'monto',
        'concepto',
        'evento_id',
        'referencia_tipo',
        'referencia_id',
        'registrado_por',
        'occurred_at',
    ];

    protected function casts(): array
    {
        return [
            'monto' => 'decimal:2',
            'occurred_at' => 'datetime',
        ];
    }

    public function organizacion(): BelongsTo
    {
        return $this->belongsTo(Organizacion::class, 'organizacion_id');
    }

    public function cuenta(): BelongsTo
    {
        return $this->belongsTo(ClubesCajaCuenta::class, 'cuenta_id');
    }

    public function cuentaBancaria(): BelongsTo
    {
        return $this->belongsTo(CuentaBancaria::class, 'cuenta_bancaria_id');
    }

    public function evento(): BelongsTo
    {
        return $this->belongsTo(Event::class, 'evento_id');
    }

    public function registrador(): BelongsTo
    {
        return $this->belongsTo(User::class, 'registrado_por');
    }

    public function signedAmount(): float
    {
        $monto = (float) $this->monto;

        return $this->tipo === self::TIPO_EGRESO ? -abs($monto) : abs($monto);
    }
}
