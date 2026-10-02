<?php

namespace App\Modules\Events\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class TipoEvento extends Model
{
    public const SLUG_ACTIVIDAD = 'actividad';

    public const SLUG_ACTIVIDAD_ECONOMICA = 'actividad-economica';

    public const SLUG_CAMPAMENTO = 'campamento';

    public const SLUG_ESPECIALIDAD = 'especialidad';

    public const SLUG_ESPECIALIDAD_LEGACY = 'clase';

    public const SLUG_INVESTIDURA = 'investidura';

    /** @var list<string> */
    public const SLUGS_INSCRIPCION_CLUB = [
        self::SLUG_CAMPAMENTO,
        self::SLUG_ESPECIALIDAD,
        self::SLUG_ESPECIALIDAD_LEGACY,
        self::SLUG_INVESTIDURA,
    ];

    protected $table = 'tipo_evento';

    protected $fillable = [
        'nombre',
        'slug',
        'descripcion',
        'color',
        'icono',
        'orden',
        'estado',
    ];

    protected function casts(): array
    {
        return [
            'estado' => 'boolean',
            'orden' => 'integer',
        ];
    }

    public function events(): HasMany
    {
        return $this->hasMany(Event::class, 'tipo_evento_id');
    }
}
