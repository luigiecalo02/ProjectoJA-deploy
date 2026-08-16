<?php

namespace App\Modules\Organizations\Models;

use App\Modules\Clubs\Models\Persona;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class PersonaOrganizacion extends Model
{
    protected $table = 'persona_organizacion';

    protected $fillable = [
        'persona_id',
        'organizacion_id',
        'fecha_inicio',
        'fecha_fin',
        'estado',
    ];

    protected function casts(): array
    {
        return [
            'fecha_inicio' => 'date',
            'fecha_fin' => 'date',
            'estado' => 'boolean',
        ];
    }

    public function persona(): BelongsTo
    {
        return $this->belongsTo(Persona::class, 'persona_id');
    }

    public function organizacion(): BelongsTo
    {
        return $this->belongsTo(Organizacion::class, 'organizacion_id');
    }

    public function rolesAsignados(): HasMany
    {
        return $this->hasMany(PersonaOrganizacionRol::class, 'persona_organizacion_id');
    }
}
