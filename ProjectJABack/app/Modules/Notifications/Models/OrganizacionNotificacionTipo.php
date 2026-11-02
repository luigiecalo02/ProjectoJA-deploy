<?php

namespace App\Modules\Notifications\Models;

use App\Modules\Organizations\Models\Organizacion;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class OrganizacionNotificacionTipo extends Model
{
    protected $table = 'organizacion_notificacion_tipos';

    protected $fillable = [
        'organizacion_id',
        'tipo',
        'activo',
    ];

    protected function casts(): array
    {
        return [
            'activo' => 'boolean',
        ];
    }

    public function organizacion(): BelongsTo
    {
        return $this->belongsTo(Organizacion::class, 'organizacion_id');
    }
}
