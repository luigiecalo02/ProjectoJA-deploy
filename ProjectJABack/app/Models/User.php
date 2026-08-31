<?php

namespace App\Models;

use App\Modules\Clubs\Models\Club;
use App\Modules\Clubs\Models\Persona;
use App\Modules\Users\Models\Permission;
use App\Modules\Users\Models\Role;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\HasApiTokens;

class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasApiTokens, HasFactory, Notifiable, SoftDeletes;

    protected $fillable = [
        'persona_id',
        'active_organizacion_id',
        'active_rol_id',
        'name',
        'email',
        'password',
        'avatar_url',
        'provider',
        'provider_id',
        'google_id',
        'facebook_id',
        'is_active',
        'is_super',
        'is_admin',
        'email_verified_at',
    ];

    protected $hidden = [
        'password',
        'remember_token',
        'email_verification_code_hash',
    ];

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'email_verification_expires_at' => 'datetime',
            'password' => 'hashed',
            'is_active' => 'boolean',
            'is_super' => 'boolean',
            'is_admin' => 'boolean',
        ];
    }

    public function clubs(): BelongsToMany
    {
        return $this->belongsToMany(
            Club::class,
            'club_user',
            'user_id',
            'club_id'
        )->withTimestamps();
    }

    /**
     * La relación vive en users.persona_id.
     */
    public function persona(): BelongsTo
    {
        return $this->belongsTo(Persona::class, 'persona_id');
    }

    public function isSuperAdmin(): bool
    {
        return (bool) $this->is_super;
    }

    public function isPlatformAdmin(): bool
    {
        return $this->isSuperAdmin() || (bool) $this->is_admin;
    }

    public function hasRole(string $role): bool
    {
        if ($role === 'super_admin' && $this->isSuperAdmin()) {
            return true;
        }

        if ($role === 'admin' && $this->isPlatformAdmin()) {
            return true;
        }

        if (! $this->persona_id) {
            return false;
        }

        return DB::table('persona_organizacion_rol')
            ->join(
                'persona_organizacion',
                'persona_organizacion.id',
                '=',
                'persona_organizacion_rol.persona_organizacion_id'
            )
            ->join('roles', 'roles.id', '=', 'persona_organizacion_rol.rol_id')
            ->where('persona_organizacion.persona_id', $this->persona_id)
            ->where('persona_organizacion.estado', true)
            ->where('roles.name', $role)
            ->exists();
    }

    public function hasPermission(string $permission): bool
    {
        if ($this->isSuperAdmin() && $this->active_organizacion_id === null) {
            return true;
        }

        return in_array($permission, $this->permissionNames(), true);
    }

    public function hasCatalogPermission(string $module, string $action): bool
    {
        return $this->hasPermission("lugares.{$action}")
            || $this->hasPermission("{$module}.{$action}");
    }

    /**
     * @return list<string>
     */
    public function permissionNames(): array
    {
        if ($this->isSuperAdmin() && $this->active_organizacion_id === null) {
            return Cache::remember('permissions:all:names', now()->addMinutes(30), function () {
                return Permission::query()
                    ->orderBy('name')
                    ->pluck('name')
                    ->values()
                    ->all();
            });
        }

        $rolKey = $this->active_rol_id ? (string) $this->active_rol_id : 'all';
        $cacheKey = "user:{$this->id}:permissions:{$rolKey}";

        return Cache::remember($cacheKey, now()->addMinutes(30), function () {
            if ($this->active_rol_id) {
                $role = Role::query()->with('permissions')->find($this->active_rol_id);

                return $role
                    ? $role->permissions->pluck('name')->unique()->values()->all()
                    : [];
            }

            if ($this->is_admin) {
                $admin = Role::query()->where('name', 'admin')->with('permissions')->first();

                return $admin
                    ? $admin->permissions->pluck('name')->unique()->values()->all()
                    : [];
            }

            if (! $this->persona_id) {
                return [];
            }

            $orgRoleIds = DB::table('persona_organizacion_rol')
                ->join(
                    'persona_organizacion',
                    'persona_organizacion.id',
                    '=',
                    'persona_organizacion_rol.persona_organizacion_id'
                )
                ->where('persona_organizacion.persona_id', $this->persona_id)
                ->where('persona_organizacion.estado', true)
                ->pluck('persona_organizacion_rol.rol_id');

            return Role::query()
                ->whereIn('id', $orgRoleIds)
                ->with('permissions')
                ->get()
                ->flatMap(fn (Role $role) => $role->permissions->pluck('name'))
                ->unique()
                ->values()
                ->all();
        });
    }

    public function clearPermissionCache(): void
    {
        Cache::forget("user:{$this->id}:permissions");
        Cache::forget("user:{$this->id}:permissions:all");
        if ($this->active_rol_id) {
            Cache::forget("user:{$this->id}:permissions:{$this->active_rol_id}");
        }
        Cache::forget('permissions:all:names');
    }

    /**
     * Roles efectivos de sesión: flags de plataforma + roles organizacionales.
     *
     * @return list<string>
     */
    public function roleNames(): array
    {
        if ($this->active_rol_id) {
            $names = collect();
            $roleName = Role::query()->where('id', $this->active_rol_id)->value('name');
            if ($roleName) {
                $names->push($roleName);
            }

            return $names->unique()->values()->all();
        }

        $names = collect();

        if ($this->is_super) {
            $names->push('super_admin');
        }
        if ($this->is_admin) {
            $names->push('admin');
        }

        if ($this->persona_id) {
            $orgNames = DB::table('persona_organizacion_rol')
                ->join(
                    'persona_organizacion',
                    'persona_organizacion.id',
                    '=',
                    'persona_organizacion_rol.persona_organizacion_id'
                )
                ->join('roles', 'roles.id', '=', 'persona_organizacion_rol.rol_id')
                ->where('persona_organizacion.persona_id', $this->persona_id)
                ->where('persona_organizacion.estado', true)
                ->pluck('roles.name');

            $names = $names->merge($orgNames);
        }

        return $names->unique()->values()->all();
    }

    /**
     * IDs de roles efectivos (organización + admin/super si aplica).
     *
     * @return list<int>
     */
    public function effectiveRoleIds(): array
    {
        if ($this->active_rol_id) {
            return [(int) $this->active_rol_id];
        }

        $ids = collect();

        if ($this->is_super) {
            $superId = Role::query()->where('is_super', true)->value('id');
            if ($superId) {
                $ids->push((int) $superId);
            }
        }

        if ($this->is_admin) {
            $adminId = Role::query()->where('name', 'admin')->value('id');
            if ($adminId) {
                $ids->push((int) $adminId);
            }
        }

        if ($this->persona_id) {
            $orgRoleIds = DB::table('persona_organizacion_rol')
                ->join(
                    'persona_organizacion',
                    'persona_organizacion.id',
                    '=',
                    'persona_organizacion_rol.persona_organizacion_id'
                )
                ->where('persona_organizacion.persona_id', $this->persona_id)
                ->where('persona_organizacion.estado', true)
                ->pluck('persona_organizacion_rol.rol_id');

            $ids = $ids->merge($orgRoleIds);
        }

        return $ids->map(fn ($id) => (int) $id)->unique()->values()->all();
    }
}
