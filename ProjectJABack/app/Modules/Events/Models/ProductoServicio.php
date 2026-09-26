<?php

namespace App\Modules\Events\Models;

use App\Modules\Organizations\Models\Organizacion;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ProductoServicio extends Model
{
    public const TIPO_SERVICIO = 'SERVICIO';

    protected $table = 'productos_servicios';

    protected $fillable = [
        'organizacion_id',
        'nombre',
        'tipo',
        'descripcion',
        'precio',
        'unidad',
        'image_path',
        'icono',
        'activo',
    ];

    protected function casts(): array
    {
        return [
            'precio' => 'decimal:2',
            'activo' => 'boolean',
        ];
    }

    public function organizacion(): BelongsTo
    {
        return $this->belongsTo(Organizacion::class, 'organizacion_id');
    }

    public function ofertasEvento(): HasMany
    {
        return $this->hasMany(EventoProductoServicio::class, 'producto_servicio_id');
    }

    public function scopeForOrganizacion(Builder $query, int $organizacionId): Builder
    {
        return $query->where('organizacion_id', $organizacionId);
    }
}
