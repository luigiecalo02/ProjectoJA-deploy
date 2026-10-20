<?php

namespace App\Modules\Settings\Models;

use App\Modules\Organizations\Models\Organizacion;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ClubesCajaCuenta extends Model
{
    public const TIPO_CAJA = 'caja';

    public const TIPO_BANCO = 'banco';

    public const TIPO_EFECTIVO_EVENTOS = 'efectivo_eventos';

    protected $table = 'clubes_caja_cuentas';

    protected $fillable = [
        'organizacion_id',
        'nombre',
        'tipo',
        'orden',
        'activa',
    ];

    protected function casts(): array
    {
        return [
            'activa' => 'boolean',
            'orden' => 'integer',
        ];
    }

    public function organizacion(): BelongsTo
    {
        return $this->belongsTo(Organizacion::class, 'organizacion_id');
    }

    public function movimientos(): HasMany
    {
        return $this->hasMany(ClubesCajaMovimiento::class, 'cuenta_id');
    }
}
