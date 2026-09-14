<?php

namespace App\Modules\Users\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Page extends Model
{
    public const FRONT_PROJECT = 'project';

    public const FRONT_CLUBES = 'clubes';

    public const FRONT_AMBOS = 'ambos';

    public const FRONTS = [
        self::FRONT_PROJECT,
        self::FRONT_CLUBES,
        self::FRONT_AMBOS,
    ];

    public const SYSTEM_KEYS = [
        'dashboard',
        'users',
        'roles',
        'settings',
        'events',
        'asistencia',
        'seguros_consulta',
        'productos_servicios',
        'clubs',
        'mi_club',
        'organizaciones',
        'personas',
        'integrantes',
        'lugares',
        'terrenos',
        'cabanas',
    ];

    protected $fillable = [
        'key',
        'name',
        'route_name',
        'icon',
        'sort_order',
        'is_active',
        'front',
        'description',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'sort_order' => 'integer',
        ];
    }

    public function isSystem(): bool
    {
        return in_array($this->key, self::SYSTEM_KEYS, true);
    }

    /**
     * @return list<string>
     */
    public static function frontsForClient(?string $front): array
    {
        $front = in_array($front, [self::FRONT_CLUBES, self::FRONT_PROJECT], true)
            ? $front
            : self::FRONT_PROJECT;

        return [$front, self::FRONT_AMBOS];
    }

    public function permissions(): HasMany
    {
        return $this->hasMany(Permission::class)->orderBy('sort_order');
    }
}
