<?php

namespace App\Modules\Auth\Services;

use App\Models\User;
use App\Modules\Clubs\Models\Club;
use App\Modules\Organizations\Models\Organizacion;
use App\Modules\Organizations\Models\PersonaOrganizacion;
use App\Modules\Organizations\Services\OrganizationAccessService;
use App\Modules\Users\Models\Role;
use Illuminate\Support\Facades\Cache;
use Illuminate\Validation\ValidationException;

/**
 * Contexto de sesión: organización + rol con el que el usuario ingresa.
 */
final class SessionContextService
{
    public function __construct(
        private readonly OrganizationAccessService $orgAccess,
    ) {}

    /**
     * @return list<array<string, mixed>>
     */
    public function options(User $user): array
    {
        $options = [];

        if ($user->isPlatformAdmin()) {
            $role = Role::query()
                ->where('name', $user->isSuperAdmin() ? 'super_admin' : 'admin')
                ->first();

            if ($role) {
                $options[] = $this->platformOption($role);
            }
        }

        if (! $user->persona_id) {
            return $options;
        }

        $rows = PersonaOrganizacion::query()
            ->with([
                'organizacion:id,nombre,codigo,tipo_organizacion_id',
                'organizacion.tipo:id,nombre',
                'rolesAsignados.rol:id,name,display_name,description,icon',
            ])
            ->where('persona_id', $user->persona_id)
            ->where('estado', true)
            ->get();

        $clubOrgIds = [];
        foreach ($rows as $po) {
            $org = $po->organizacion;
            if ($org && $this->isClubTipo((int) $org->tipo_organizacion_id)) {
                $clubOrgIds[(int) $org->id] = true;
            }
        }

        $clubsByOrg = $clubOrgIds === []
            ? collect()
            : Club::query()
                ->whereIn('organizacion_id', array_keys($clubOrgIds))
                ->get(['id', 'organizacion_id', 'nombre', 'logo', 'color_principal', 'color_secundario', 'tipos'])
                ->keyBy('organizacion_id');

        foreach ($rows as $po) {
            $org = $po->organizacion;
            if (! $org) {
                continue;
            }

            $tipoId = (int) $org->tipo_organizacion_id;
            $isClub = $this->isClubTipo($tipoId);
            /** @var Club|null $club */
            $club = $isClub ? $clubsByOrg->get((int) $org->id) : null;

            foreach ($po->rolesAsignados as $assignment) {
                $rol = $assignment->rol;
                if (! $rol) {
                    continue;
                }

                $options[] = [
                    'key' => "org:{$org->id}:rol:{$rol->id}",
                    'organizacion_id' => (int) $org->id,
                    'organizacion_nombre' => $org->nombre,
                    'organizacion_codigo' => $org->codigo,
                    'tipo_organizacion_id' => $tipoId,
                    'tipo_nombre' => $org->tipo?->nombre,
                    'rol_id' => (int) $rol->id,
                    'rol_name' => $rol->name,
                    'rol_display_name' => $rol->display_name ?: $rol->name,
                    'descripcion' => $rol->description
                        ?: $this->fallbackDescription($tipoId, $rol->name),
                    'theme' => $this->themeForTipo($tipoId),
                    'icon' => $rol->icon ?: $this->iconForTipo($tipoId),
                    'is_platform' => false,
                    'is_club' => $isClub,
                    'club_id' => $isClub ? ($club?->id ? (int) $club->id : null) : null,
                    'club_tipos' => $isClub ? $this->clubTipos($club) : [],
                    'club_logo_url' => $this->publicFileUrl($club?->logo),
                    'color_principal' => $club?->color_principal,
                    'color_secundario' => $club?->color_secundario,
                ];
            }
        }

        return $options;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function current(User $user): ?array
    {
        if (! $user->active_rol_id) {
            return null;
        }

        foreach ($this->options($user) as $option) {
            $sameOrg = ($option['organizacion_id'] ?? null) === ($user->active_organizacion_id
                ? (int) $user->active_organizacion_id
                : null);
            $sameRol = (int) $option['rol_id'] === (int) $user->active_rol_id;
            if ($sameOrg && $sameRol) {
                return $option;
            }
        }

        return null;
    }

    public function requiresSelection(User $user): bool
    {
        $options = $this->options($user);
        if ($options === []) {
            return false;
        }

        return $this->current($user) === null;
    }

    /**
     * Si solo hay una opción y no hay contexto, lo fija automáticamente.
     */
    public function ensureContext(User $user): User
    {
        if ($this->current($user) !== null) {
            return $user;
        }

        $options = $this->options($user);
        if (count($options) === 1) {
            return $this->setContext(
                $user,
                $options[0]['organizacion_id'] ?? null,
                (int) $options[0]['rol_id'],
            );
        }

        return $user;
    }

    public function setContext(User $user, ?int $organizacionId, int $rolId): User
    {
        $match = null;
        foreach ($this->options($user) as $option) {
            $optionOrg = $option['organizacion_id'] ?? null;
            if ($optionOrg === $organizacionId && (int) $option['rol_id'] === $rolId) {
                $match = $option;
                break;
            }
        }

        if ($match === null) {
            throw ValidationException::withMessages([
                'contexto' => ['La organización y el rol seleccionados no están disponibles para tu usuario.'],
            ]);
        }

        $previousRolId = $user->active_rol_id;

        $user->forceFill([
            'active_organizacion_id' => $organizacionId,
            'active_rol_id' => $rolId,
        ])->save();

        if ($previousRolId && (int) $previousRolId !== $rolId) {
            Cache::forget("user:{$user->id}:permissions:{$previousRolId}");
        }

        $user->clearPermissionCache();

        return $user->fresh();
    }

    public function clearContext(User $user): User
    {
        $previousRolId = $user->active_rol_id;

        $user->forceFill([
            'active_organizacion_id' => null,
            'active_rol_id' => null,
        ])->save();

        if ($previousRolId) {
            Cache::forget("user:{$user->id}:permissions:{$previousRolId}");
        }

        $user->clearPermissionCache();

        return $user->fresh();
    }

    /**
     * @return array<string, mixed>
     */
    private function platformOption(Role $role): array
    {
        return [
            'key' => 'platform',
            'organizacion_id' => null,
            'organizacion_nombre' => 'Administración del sistema',
            'organizacion_codigo' => null,
            'tipo_organizacion_id' => null,
            'tipo_nombre' => 'Plataforma',
            'rol_id' => (int) $role->id,
            'rol_name' => $role->name,
            'rol_display_name' => $role->display_name ?: $role->name,
            'descripcion' => 'Acceso completo a la plataforma, organizaciones, usuarios y configuración.',
            'theme' => 'navy',
            'icon' => $role->icon ?: 'pi pi-shield',
            'is_platform' => true,
            'is_club' => false,
            'club_id' => null,
            'club_tipos' => [],
            'club_logo_url' => null,
            'color_principal' => null,
            'color_secundario' => null,
        ];
    }

    /**
     * @return list<string>
     */
    private function clubTipos(?Club $club): array
    {
        if (! $club || ! is_array($club->tipos)) {
            return [];
        }

        return array_values(array_filter(
            $club->tipos,
            static fn ($tipo) => is_string($tipo) && $tipo !== '',
        ));
    }

    private function isClubTipo(int $tipoId): bool
    {
        return in_array($tipoId, [
            Organizacion::TIPO_CLUB,
            Organizacion::TIPO_AVENTUREROS,
            Organizacion::TIPO_CONQUISTADORES,
            Organizacion::TIPO_GUIAS_MAYORES,
        ], true);
    }

    private function themeForTipo(int $tipoId): string
    {
        return match ($tipoId) {
            Organizacion::TIPO_UNION => 'indigo',
            Organizacion::TIPO_ASOCIACION => 'green',
            Organizacion::TIPO_DISTRITO => 'cyan',
            Organizacion::TIPO_IGLESIA => 'blue',
            Organizacion::TIPO_CLUB => 'orange',
            Organizacion::TIPO_AVENTUREROS => 'teal',
            Organizacion::TIPO_CONQUISTADORES => 'amber',
            Organizacion::TIPO_GUIAS_MAYORES => 'rose',
            default => 'slate',
        };
    }

    private function iconForTipo(int $tipoId): string
    {
        return match ($tipoId) {
            Organizacion::TIPO_UNION => 'pi pi-globe',
            Organizacion::TIPO_ASOCIACION => 'pi pi-map',
            Organizacion::TIPO_DISTRITO => 'pi pi-compass',
            Organizacion::TIPO_IGLESIA => 'pi pi-building',
            Organizacion::TIPO_CLUB,
            Organizacion::TIPO_AVENTUREROS,
            Organizacion::TIPO_CONQUISTADORES,
            Organizacion::TIPO_GUIAS_MAYORES => 'pi pi-flag',
            default => 'pi pi-sitemap',
        };
    }

    private function fallbackDescription(int $tipoId, string $roleName): string
    {
        $scope = match ($tipoId) {
            Organizacion::TIPO_ASOCIACION => 'la asociación, iglesias y clubes de su alcance',
            Organizacion::TIPO_DISTRITO => 'el distrito y sus iglesias',
            Organizacion::TIPO_IGLESIA => 'la iglesia local y sus clubes',
            Organizacion::TIPO_CLUB,
            Organizacion::TIPO_AVENTUREROS,
            Organizacion::TIPO_CONQUISTADORES,
            Organizacion::TIPO_GUIAS_MAYORES => 'el club, integrantes y actividades',
            default => 'la organización seleccionada',
        };

        return "Ingresar como {$roleName} para gestionar {$scope}.";
    }

    private function publicFileUrl(?string $value): ?string
    {
        if (! is_string($value) || $value === '') {
            return null;
        }

        if (str_starts_with($value, 'http://') || str_starts_with($value, 'https://')) {
            return $value;
        }

        $path = ltrim($value, '/');
        if (str_starts_with($path, 'storage/')) {
            return url($path);
        }

        return url('storage/'.$path);
    }
}
