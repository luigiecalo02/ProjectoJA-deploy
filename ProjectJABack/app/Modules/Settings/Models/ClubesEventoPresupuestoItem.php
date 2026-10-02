<?php

namespace App\Modules\Settings\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ClubesEventoPresupuestoItem extends Model
{
    public const TIPO_INDIVIDUAL = 'individual';

    public const TIPO_GRUPAL = 'grupal';

    public const DEST_MIEMBROS = 'miembros';

    public const DEST_ACOMPANANTES = 'acompanantes';

    public const DEST_AMBOS = 'ambos';

    protected $table = 'clubes_evento_presupuesto_items';

    protected $fillable = [
        'presupuesto_id',
        'concepto',
        'tipo',
        'monto',
        'destinatario',
        'orden',
    ];

    protected function casts(): array
    {
        return [
            'presupuesto_id' => 'integer',
            'monto' => 'decimal:2',
            'orden' => 'integer',
        ];
    }

    public function presupuesto(): BelongsTo
    {
        return $this->belongsTo(ClubesEventoPresupuesto::class, 'presupuesto_id');
    }
}
