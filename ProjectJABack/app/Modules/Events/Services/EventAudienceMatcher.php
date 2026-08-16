<?php

namespace App\Modules\Events\Services;

use App\Models\User;
use App\Modules\Clubs\Models\Club;
use App\Modules\Organizations\Models\Organizacion;
use App\Modules\Organizations\Models\TipoOrganizacion;
use App\Modules\Organizations\Services\OrganizationAccessService;

/**
 * Regla de audiencia del evento:
 * - Sin tipos (Libre): basta el alcance organizacional.
 * - Con tipos (Aventureros/Conquistadores/Guías): los clubes solo ven el evento
 *   si su ministerio coincide; personal de orgs no-club (asociación, iglesia, etc.) sí ve.
 */
final class EventAudienceMatcher
{
    public function __construct(
        private readonly OrganizationAccessService $orgAccess,
    ) {}

    /**
     * @param  list<int>  $tipoOrganizacionIds
     */
    public function actorMatchesAudience(User $actor, array $tipoOrganizacionIds): bool
    {
        $tipoOrganizacionIds = array_values(array_unique(array_map('intval', $tipoOrganizacionIds)));
        if ($tipoOrganizacionIds === []) {
            return true;
        }

        if ($this->orgAccess->bypassesOrganizationScope($actor)) {
            return true;
        }

        $membershipIds = $this->orgAccess->membershipOrganizationIds($actor);
        if ($membershipIds === []) {
            return false;
        }

        $orgs = Organizacion::query()
            ->whereIn('id', $membershipIds)
            ->get(['id', 'tipo_organizacion_id']);

        $allowedMinistries = $this->ministriesForTipoIds($tipoOrganizacionIds);
        $hasNonClubMembership = false;
        $clubOrgIds = [];

        foreach ($orgs as $org) {
            $tipoId = (int) $org->tipo_organizacion_id;
            if ($this->isClubLikeTipo($tipoId)) {
                $clubOrgIds[] = (int) $org->id;
                if (in_array($tipoId, $tipoOrganizacionIds, true)) {
                    return true;
                }
            } else {
                $hasNonClubMembership = true;
            }
        }

        // Asociación / distrito / iglesia / unión: gestionan el evento completo.
        if ($hasNonClubMembership) {
            return true;
        }

        if ($clubOrgIds === [] || $allowedMinistries === []) {
            return false;
        }

        return Club::query()
            ->whereIn('organizacion_id', $clubOrgIds)
            ->get(['id', 'organizacion_id', 'tipos'])
            ->contains(function (Club $club) use ($allowedMinistries) {
                $tipos = is_array($club->tipos) ? $club->tipos : [];

                return count(array_intersect($tipos, $allowedMinistries)) > 0;
            });
    }

    /**
     * @param  list<int>  $tipoOrganizacionIds
     * @return list<string>
     */
    public function ministriesForTipoIds(array $tipoOrganizacionIds): array
    {
        if ($tipoOrganizacionIds === []) {
            return [];
        }

        $ministries = [];
        $tipos = TipoOrganizacion::query()
            ->whereIn('id', $tipoOrganizacionIds)
            ->get(['id', 'nombre']);

        $resolvedIds = [];
        foreach ($tipos as $tipo) {
            $resolvedIds[] = (int) $tipo->id;
            $name = mb_strtolower((string) $tipo->nombre);
            if (str_contains($name, 'aventurero')) {
                $ministries[] = Club::MINISTRY_AVENTUREROS;
            } elseif (str_contains($name, 'conquistador')) {
                $ministries[] = Club::MINISTRY_CONQUISTADORES;
            } elseif (str_contains($name, 'guía') || str_contains($name, 'guia')) {
                $ministries[] = Club::MINISTRY_GUIAS;
            }
        }

        // Fallback por constantes solo si el ID no existe en catálogo.
        $fallback = [
            Organizacion::TIPO_AVENTUREROS => Club::MINISTRY_AVENTUREROS,
            Organizacion::TIPO_CONQUISTADORES => Club::MINISTRY_CONQUISTADORES,
            Organizacion::TIPO_GUIAS_MAYORES => Club::MINISTRY_GUIAS,
        ];
        foreach ($tipoOrganizacionIds as $tipoId) {
            if (in_array($tipoId, $resolvedIds, true)) {
                continue;
            }
            if (isset($fallback[$tipoId])) {
                $ministries[] = $fallback[$tipoId];
            }
        }

        return array_values(array_unique($ministries));
    }

    private function isClubLikeTipo(int $tipoId): bool
    {
        return in_array($tipoId, [
            Organizacion::TIPO_CLUB,
            Organizacion::TIPO_AVENTUREROS,
            Organizacion::TIPO_CONQUISTADORES,
            Organizacion::TIPO_GUIAS_MAYORES,
        ], true);
    }
}
