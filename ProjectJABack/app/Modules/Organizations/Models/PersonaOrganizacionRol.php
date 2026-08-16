<?php

namespace App\Modules\Organizations\Models;

use App\Modules\Users\Models\Role;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PersonaOrganizacionRol extends Model
{
    protected $table = 'persona_organizacion_rol';

    public $timestamps = false;

    protected $fillable = [
        'persona_organizacion_id',
        'rol_id',
        'created_at',
    ];

    protected function casts(): array
    {
        return [
            'created_at' => 'datetime',
        ];
    }

    public function personaOrganizacion(): BelongsTo
    {
        return $this->belongsTo(PersonaOrganizacion::class, 'persona_organizacion_id');
    }

    public function rol(): BelongsTo
    {
        return $this->belongsTo(Role::class, 'rol_id');
    }
}
