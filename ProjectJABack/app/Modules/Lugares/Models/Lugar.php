<?php

namespace App\Modules\Lugares\Models;

use App\Modules\Cabanas\Models\Cabana;
use App\Modules\Events\Models\Event;
use App\Modules\Terrains\Models\Terreno;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Lugar extends Model
{
    protected $table = 'lugares';

    public const ESTADO_ACTIVO = 'activo';

    public const ESTADO_INACTIVO = 'inactivo';

    protected $fillable = [
        'nombre',
        'descripcion',
        'latitud',
        'longitud',
        'nivel_zoom',
        'estado',
    ];

    protected function casts(): array
    {
        return [
            'latitud' => 'float',
            'longitud' => 'float',
            'nivel_zoom' => 'integer',
        ];
    }

    public function terrenos(): HasMany
    {
        return $this->hasMany(Terreno::class, 'lugar_id');
    }

    public function cabanas(): HasMany
    {
        return $this->hasMany(Cabana::class, 'lugar_id');
    }

    public function events(): HasMany
    {
        return $this->hasMany(Event::class, 'lugar_id');
    }
}
