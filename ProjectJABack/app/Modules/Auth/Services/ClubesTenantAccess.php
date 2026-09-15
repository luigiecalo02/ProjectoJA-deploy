<?php

namespace App\Modules\Auth\Services;

use App\Models\User;
use App\Modules\Organizations\Models\PersonaOrganizacion;
use App\Modules\Organizations\Services\OrganizationAccessService;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

final class ClubesTenantAccess
{
    public function __construct(private readonly OrganizationAccessService $orgAccess) {}

    public function rootId(?Request $request = null): ?int
    {
        $request ??= request();
        if (! $request instanceof Request || $request->header('X-Clubes-Client') !== 'clubes') {
            return null;
        }

        $configured = (int) config('clubes.root_organizacion_id');
        if ($configured > 0) {
            return $configured;
        }

        $fromHeader = (int) $request->header('X-Clubes-Root-Id');

        return $fromHeader > 0 ? $fromHeader : null;
    }

    public function requireRootId(?Request $request = null): int
    {
        $root = $this->rootId($request);
        if ($root === null) {
            throw ValidationException::withMessages([
                'organizacion_id' => ['Falta el club raíz. Configura VITE_CLUB_ID en el .env.'],
            ]);
        }

        return $root;
    }

    public function assertInTenant(int $organizacionId, ?Request $request = null): void
    {
        $allowed = $this->allowedOrganizationIds($request);
        if ($allowed === null || in_array($organizacionId, $allowed, true)) {
            return;
        }

        throw ValidationException::withMessages([
            'organizacion_id' => ['Esa organización no pertenece a este club.'],
        ]);
    }

    /**
     * @return list<int>|null
     */
    public function allowedOrganizationIds(?Request $request = null): ?array
    {
        $root = $this->rootId($request);
        if ($root === null) {
            return null;
        }

        return array_values(array_unique([
            $root,
            ...$this->orgAccess->descendantIds($root),
        ]));
    }

    public function userMayEnter(User $user, ?Request $request = null): bool
    {
        if ($user->isSuperAdmin()) {
            return true;
        }

        $allowed = $this->allowedOrganizationIds($request);
        if ($allowed === null) {
            return true;
        }

        if (! $user->persona_id) {
            return false;
        }

        $memberships = PersonaOrganizacion::query()
            ->where('persona_id', $user->persona_id)
            ->where('estado', true)
            ->pluck('organizacion_id')
            ->map(fn ($id) => (int) $id)
            ->all();

        return count(array_intersect($memberships, $allowed)) > 0;
    }

    public function organizationIdForUser(User $user, ?Request $request = null): ?int
    {
        $allowed = $this->allowedOrganizationIds($request);
        $active = (int) ($user->active_organizacion_id ?? 0);
        if ($active > 0 && ($allowed === null || in_array($active, $allowed, true))) {
            return $active;
        }

        if (! $user->persona_id) {
            return $this->rootId($request);
        }

        $memberships = PersonaOrganizacion::query()
            ->with('organizacion:id,tipo_organizacion_id')
            ->where('persona_id', $user->persona_id)
            ->where('estado', true)
            ->when($allowed !== null, fn ($query) => $query->whereIn('organizacion_id', $allowed))
            ->get();

        if ($memberships->isEmpty()) {
            return $this->rootId($request);
        }

        if ($memberships->count() === 1) {
            return (int) $memberships->first()->organizacion_id;
        }

        $club = $memberships->first(
            static fn (PersonaOrganizacion $row): bool => (bool) $row->organizacion?->isClubTipo()
        );

        return (int) ($club?->organizacion_id ?? $memberships->first()->organizacion_id);
    }

    public function assertUserMayEnter(User $user, ?Request $request = null): void
    {
        if ($this->userMayEnter($user, $request)) {
            return;
        }

        throw ValidationException::withMessages([
            'email' => ['Tu organización no pertenece a este club. Solo pueden ingresar organizaciones hijas o un superadministrador.'],
        ]);
    }

    /**
     * @param  list<array<string, mixed>>  $options
     * @return list<array<string, mixed>>
     */
    public function filterOptions(User $user, array $options, ?Request $request = null): array
    {
        $allowed = $this->allowedOrganizationIds($request);
        if ($allowed === null || $user->isSuperAdmin()) {
            return $options;
        }

        return array_values(array_filter(
            $options,
            static function (array $option) use ($allowed): bool {
                $orgId = $option['organizacion_id'] ?? null;
                if ($orgId === null) {
                    return false;
                }

                return in_array((int) $orgId, $allowed, true);
            },
        ));
    }
}
