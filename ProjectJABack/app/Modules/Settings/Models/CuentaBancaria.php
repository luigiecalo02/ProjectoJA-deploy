<?php

namespace App\Modules\Settings\Models;

use App\Modules\Shared\Models\StoredFile;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CuentaBancaria extends Model
{
    public const TIPO_AHORROS = 'ahorros';

    public const TIPO_CORRIENTE = 'corriente';

    protected $table = 'cuentas_bancarias';

    protected $fillable = [
        'nombre',
        'banco',
        'tipo_cuenta',
        'numero_cuenta',
        'titular',
        'identificacion_titular',
        'qr_file_id',
        'activo',
        'orden',
    ];

    protected function casts(): array
    {
        return [
            'activo' => 'boolean',
            'orden' => 'integer',
        ];
    }

    public function qrFile(): BelongsTo
    {
        return $this->belongsTo(StoredFile::class, 'qr_file_id');
    }
}
