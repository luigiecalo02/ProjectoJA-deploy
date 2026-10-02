<?php

namespace App\Modules\Settings\Models;

use App\Modules\Events\Models\Event;
use App\Modules\Organizations\Models\Organizacion;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ClubesEventoPresupuesto extends Model
{
    protected $table = 'clubes_evento_presupuestos';

    protected $fillable = [
        'evento_id',
        'organizacion_id',
        'nombre',
        'activo',
        'acompanantes_count',
    ];

    protected function casts(): array
    {
        return [
            'evento_id' => 'integer',
            'organizacion_id' => 'integer',
            'activo' => 'boolean',
            'acompanantes_count' => 'integer',
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

    public function items(): HasMany
    {
        return $this->hasMany(ClubesEventoPresupuestoItem::class, 'presupuesto_id')
            ->orderBy('orden')
            ->orderBy('id');
    }
}
