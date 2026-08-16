<?php

namespace App\Modules\Events\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class TipoSeguro extends Model
{
    protected $table = 'tipos_seguro';

    public const TIPO_ANUAL = 'ANUAL';

    public const TIPO_EVENTO = 'EVENTO';

    protected $fillable = [
        'nombre',
        'tipo',
        'descripcion',
        'duracion_dias',
        'requiere_evento',
        'activo',
    ];

    protected function casts(): array
    {
        return [
            'requiere_evento' => 'boolean',
            'activo' => 'boolean',
            'duracion_dias' => 'integer',
        ];
    }

    public function seguros(): HasMany
    {
        return $this->hasMany(Seguro::class, 'tipo_seguro_id');
    }
}
