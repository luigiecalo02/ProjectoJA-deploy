<?php

namespace App\Modules\Clubs\Models;

use App\Models\User;
use App\Modules\Organizations\Models\Organizacion;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Club extends Model
{
    use SoftDeletes;

    protected $table = 'clubes';

    public const MINISTRY_CONQUISTADORES = 'conquistadores';

    public const MINISTRY_AVENTUREROS = 'aventureros';

    public const MINISTRY_GUIAS = 'guias_mayores';

    public const MINISTRIES = [
        self::MINISTRY_CONQUISTADORES,
        self::MINISTRY_AVENTUREROS,
        self::MINISTRY_GUIAS,
    ];

    public const BOARD_DIRECTOR = 'director';

    public const BOARD_SUBDIRECTOR = 'subdirector';

    public const BOARD_SECRETARIA = 'secretaria';

    public const BOARD_TESORERO = 'tesorero';

    public const BOARD_POSITIONS = [
        self::BOARD_DIRECTOR,
        self::BOARD_SUBDIRECTOR,
        self::BOARD_SECRETARIA,
        self::BOARD_TESORERO,
    ];

    public const BOARD_ROLE_MAP = [
        self::BOARD_DIRECTOR => 'director',
        self::BOARD_SUBDIRECTOR => 'subdirector',
        self::BOARD_SECRETARIA => 'secretario',
        self::BOARD_TESORERO => 'tesorero',
    ];

    public static function roleForBoardPosition(string $position, ?array $tipos = null): string
    {
        return self::BOARD_ROLE_MAP[$position]
            ?? throw new \InvalidArgumentException("Cargo de directiva no válido: {$position}");
    }

    public static function positionForRoleName(string $roleName): ?string
    {
        $flipped = array_flip(self::BOARD_ROLE_MAP);

        return $flipped[$roleName] ?? null;
    }

    /**
     * @return list<string>
     */
    public static function boardRoleNames(): array
    {
        return array_values(array_unique(array_values(self::BOARD_ROLE_MAP)));
    }

    protected $fillable = [
        'organizacion_id',
        'nombre',
        'nombre_corto',
        'lema',
        'logo',
        'fecha_fundacion',
        'descripcion',
        'color_principal',
        'color_secundario',
        'sitio_web',
        'distrito',
        'ciudad',
        'tipos',
        'is_active',
        'created_by',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'tipos' => 'array',
            'fecha_fundacion' => 'date',
        ];
    }

    public function organizacion(): BelongsTo
    {
        return $this->belongsTo(Organizacion::class, 'organizacion_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function users(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'club_user', 'club_id', 'user_id')
            ->withTimestamps();
    }
}
