<?php

namespace App\Modules\Notifications\Services;

use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

final class RecipientResolver
{
    /** @var list<string> */
    private const DIRECTIVA_ROLES = ['director', 'subdirector', 'secretario', 'tesorero'];

    /**
     * @return Collection<int, User>
     */
    public function usersForPersona(int $personaId): Collection
    {
        if ($personaId <= 0) {
            return collect();
        }

        return User::query()
            ->where('persona_id', $personaId)
            ->where('is_active', true)
            ->get();
    }

    /**
     * Usuarios activos con cargo de directiva en la organización.
     *
     * @return Collection<int, User>
     */
    public function directivaUsersForOrganizacion(int $organizacionId): Collection
    {
        if ($organizacionId <= 0) {
            return collect();
        }

        $personaIds = DB::table('persona_organizacion_rol')
            ->join(
                'persona_organizacion',
                'persona_organizacion.id',
                '=',
                'persona_organizacion_rol.persona_organizacion_id',
            )
            ->join('roles', 'roles.id', '=', 'persona_organizacion_rol.rol_id')
            ->where('persona_organizacion.organizacion_id', $organizacionId)
            ->where('persona_organizacion.estado', true)
            ->whereIn('roles.name', self::DIRECTIVA_ROLES)
            ->distinct()
            ->pluck('persona_organizacion.persona_id');

        if ($personaIds->isEmpty()) {
            return collect();
        }

        return User::query()
            ->whereIn('persona_id', $personaIds)
            ->where('is_active', true)
            ->get();
    }
}
