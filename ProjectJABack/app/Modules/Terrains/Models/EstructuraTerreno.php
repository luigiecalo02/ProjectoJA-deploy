<?php

namespace App\Modules\Terrains\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class EstructuraTerreno extends Model
{
    protected $table = 'estructuras_terreno';

    public const TIPO_GENERAL = 'general';

    public const TIPO_BANOS = 'banos';

    public const TIPO_COMEDOR = 'comedor';

    public const TIPO_COCINA = 'cocina';

    public const TIPO_ESTACIONAMIENTO = 'estacionamiento';

    public const TIPO_ESCENARIO = 'escenario';

    public const TIPO_ENFERMERIA = 'enfermeria';

    public const TIPO_ALMACEN = 'almacen';

    public const TIPO_OTRO = 'otro';

    public const TIPOS = [
        self::TIPO_GENERAL,
        self::TIPO_BANOS,
        self::TIPO_COMEDOR,
        self::TIPO_COCINA,
        self::TIPO_ESTACIONAMIENTO,
        self::TIPO_ESCENARIO,
        self::TIPO_ENFERMERIA,
        self::TIPO_ALMACEN,
        self::TIPO_OTRO,
    ];

    protected $fillable = [
        'terreno_id',
        'nombre',
        'tipo',
        'descripcion',
        'geometria',
        'area',
        'perimetro',
        'color',
        'orden',
        'estado',
    ];

    protected function casts(): array
    {
        return [
            'geometria' => 'array',
            'area' => 'float',
            'perimetro' => 'float',
            'orden' => 'integer',
        ];
    }

    public function terreno(): BelongsTo
    {
        return $this->belongsTo(Terreno::class, 'terreno_id');
    }
}
