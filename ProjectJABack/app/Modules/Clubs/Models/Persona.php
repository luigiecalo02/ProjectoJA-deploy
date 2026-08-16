<?php

namespace App\Modules\Clubs\Models;

use App\Models\User;
use App\Modules\Organizations\Models\PersonaOrganizacion;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Collection;

class Persona extends Model
{
    use SoftDeletes;

    protected $table = 'personas';

    protected $fillable = [
        'tipo_identificacion',
        'identificacion',
        'nombre1',
        'nombre2',
        'apellido1',
        'apellido2',
        'fecha_nacimiento',
        'sexo',
        'telefono',
        'correo',
        'direccion_actual',
    ];

    protected function casts(): array
    {
        return [
            'fecha_nacimiento' => 'date',
        ];
    }

    protected function fullName(): Attribute
    {
        return Attribute::get(function (): string {
            return collect([
                $this->nombre1,
                $this->nombre2,
                $this->apellido1,
                $this->apellido2,
            ])->filter()->implode(' ');
        });
    }

    public function user(): HasOne
    {
        return $this->hasOne(User::class, 'persona_id');
    }

    public function organizaciones(): HasMany
    {
        return $this->hasMany(PersonaOrganizacion::class, 'persona_id');
    }

    /**
     * Clubes vinculados por organizaciones tipo Club activas de la persona.
     *
     * @return Collection<int, Club>
     */
    public function clubsViaOrganizacion(): Collection
    {
        $orgIds = $this->organizaciones()
            ->where('estado', true)
            ->pluck('organizacion_id')
            ->map(fn ($id) => (int) $id)
            ->all();

        if ($orgIds === []) {
            return collect();
        }

        return Club::query()
            ->whereIn('organizacion_id', $orgIds)
            ->orderBy('nombre')
            ->get();
    }
}
