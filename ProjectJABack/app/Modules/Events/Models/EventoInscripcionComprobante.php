<?php

namespace App\Modules\Events\Models;

use App\Models\User;
use App\Modules\Shared\Models\StoredFile;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class EventoInscripcionComprobante extends Model
{
    protected $table = 'evento_inscripcion_comprobante';

    public const ESTADO_PENDIENTE = 'pendiente';

    public const ESTADO_APROBADO = 'aprobado';

    public const ESTADO_RECHAZADO = 'rechazado';

    protected $fillable = [
        'inscripcion_id',
        'movimiento_id',
        'file_id',
        'valor',
        'estado',
        'observacion',
        'subido_por',
        'revisado_por',
        'revisado_at',
    ];

    protected function casts(): array
    {
        return [
            'valor' => 'decimal:2',
            'revisado_at' => 'datetime',
        ];
    }

    public function inscripcion(): BelongsTo
    {
        return $this->belongsTo(EventoInscripcion::class, 'inscripcion_id');
    }

    public function movimiento(): BelongsTo
    {
        return $this->belongsTo(EventoInscripcionMovimiento::class, 'movimiento_id');
    }

    public function archivo(): BelongsTo
    {
        return $this->belongsTo(StoredFile::class, 'file_id');
    }

    public function subidoPor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'subido_por');
    }

    public function revisadoPor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'revisado_por');
    }

    public function comentarios(): HasMany
    {
        return $this->hasMany(
            EventoInscripcionComprobanteComentario::class,
            'comprobante_id',
        )->orderBy('created_at');
    }
}
