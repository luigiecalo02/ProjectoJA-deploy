<?php

namespace App\Modules\Settings\Models;

use App\Models\User;
use App\Modules\Clubs\Models\Persona;
use App\Modules\Events\Models\Event;
use App\Modules\Organizations\Models\Organizacion;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ClubesCajaMovimiento extends Model
{
    public const TIPO_INGRESO = 'ingreso';

    public const TIPO_EGRESO = 'egreso';

    public const TIPO_TRANSFERENCIA = 'transferencia';

    public const ORIGEN_ABONO = 'abono';

    public const ORIGEN_MANUAL = 'manual';

    public const ORIGEN_AJUSTE = 'ajuste';

    public const ORIGEN_GASTO = 'gasto';

    public const ORIGEN_TRANSFERENCIA = 'transferencia';

    public const DESTINO_PARTICIPANTES = 'participantes';

    public const DESTINO_DIEZMO = 'diezmo';

    public const DESTINO_OFRENDA = 'ofrenda';

    public const DESTINO_CLUB = 'club';

    public const DESTINO_EXTRAS = 'extras';

    public const DESTINO_CAPITAL = 'capital';

    public const DESTINOS = [
        self::DESTINO_PARTICIPANTES,
        self::DESTINO_DIEZMO,
        self::DESTINO_OFRENDA,
        self::DESTINO_CLUB,
        self::DESTINO_EXTRAS,
        self::DESTINO_CAPITAL,
    ];

    protected $table = 'clubes_caja_movimientos';

    protected $fillable = [
        'organizacion_id',
        'cuenta_id',
        'cuenta_bancaria_id',
        'tipo',
        'origen',
        'destino',
        'monto',
        'concepto',
        'evento_id',
        'persona_id',
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

    public function persona(): BelongsTo
    {
        return $this->belongsTo(Persona::class, 'persona_id');
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

    /**
     * Ajustes dirigidos a la ganancia de un participante (ingreso +, egreso -).
     *
     * @return array<int, array<int, float>>
     */
    public static function ajustesParticipantes(int $orgId, ?int $eventoId = null): array
    {
        $out = [];
        static::query()
            ->where('organizacion_id', $orgId)
            ->where('destino', self::DESTINO_PARTICIPANTES)
            ->whereNotNull('persona_id')
            ->whereNotNull('evento_id')
            ->when($eventoId, fn ($query) => $query->where('evento_id', $eventoId))
            ->get(['evento_id', 'persona_id', 'tipo', 'monto'])
            ->each(function (self $row) use (&$out): void {
                $evento = (int) $row->evento_id;
                $persona = (int) $row->persona_id;
                $out[$evento][$persona] = round(($out[$evento][$persona] ?? 0) + $row->signedAmount(), 2);
            });

        return $out;
    }
}
