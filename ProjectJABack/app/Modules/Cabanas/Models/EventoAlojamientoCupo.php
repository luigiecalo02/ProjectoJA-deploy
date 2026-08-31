<?php

namespace App\Modules\Cabanas\Models;

use App\Models\User;
use App\Modules\Events\Models\Event;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class EventoAlojamientoCupo extends Model
{
    public const ESTADO_ABIERTO = 'abierto';

    public const ESTADO_CERRADO = 'cerrado';

    protected $table = 'evento_alojamiento_cupos';

    protected $fillable = [
        'evento_id', 'user_id', 'cupos', 'estado', 'cerrado_at', 'created_by',
    ];

    protected function casts(): array
    {
        return [
            'cupos' => 'integer',
            'cerrado_at' => 'datetime',
        ];
    }

    public function evento(): BelongsTo
    {
        return $this->belongsTo(Event::class, 'evento_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function asignaciones(): HasMany
    {
        return $this->hasMany(AsignacionCama::class, 'evento_alojamiento_cupo_id');
    }

    public function isOpen(): bool
    {
        return $this->estado === self::ESTADO_ABIERTO;
    }

    public function usados(): int
    {
        return $this->asignaciones()
            ->where('estado', AsignacionCama::ESTADO_ACTIVA)
            ->count();
    }

    public function restantes(): int
    {
        return max(0, (int) $this->cupos - $this->usados());
    }
}
