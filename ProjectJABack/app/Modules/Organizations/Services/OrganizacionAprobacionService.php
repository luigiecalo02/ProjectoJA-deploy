<?php

namespace App\Modules\Organizations\Services;

use App\Models\User;
use App\Modules\Auth\Services\AccountMailService;
use App\Modules\Clubs\Models\Club;
use App\Modules\Clubs\Services\ClubService;
use App\Modules\Organizations\Models\Organizacion;
use App\Modules\Organizations\Models\PersonaOrganizacion;
use App\Modules\Shared\Services\AuditLogger;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class OrganizacionAprobacionService
{
    public function __construct(
        private readonly OrganizacionService $organizaciones,
        private readonly ClubService $clubService,
        private readonly AccountMailService $accountMail,
        private readonly AuditLogger $auditLogger,
        private readonly OrganizacionRealtimeNotifier $realtime,
    ) {}

    /**
     * @return list<array<string, mixed>>
     */
    public function approvedOfTipo(int $tipoId, ?int $padreId = null): array
    {
        $query = Organizacion::query()
            ->where('tipo_organizacion_id', $tipoId)
            ->where('estado', true)
            ->where('estado_aprobacion', Organizacion::APROBACION_APROBADA)
            ->orderBy('nombre');

        if ($padreId) {
            $query->where('organizacion_padre_id', $padreId);
        }

        return $query
            ->get(['id', 'nombre', 'codigo', 'tipo_organizacion_id', 'organizacion_padre_id'])
            ->map(fn (Organizacion $org) => [
                'id' => (int) $org->id,
                'nombre' => $org->nombre,
                'codigo' => $org->codigo,
                'tipo_organizacion_id' => (int) $org->tipo_organizacion_id,
                'organizacion_padre_id' => $org->organizacion_padre_id ? (int) $org->organizacion_padre_id : null,
            ])
            ->values()
            ->all();
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function approvedClubsForIglesia(int $iglesiaId): array
    {
        $orgIds = Organizacion::query()
            ->where('organizacion_padre_id', $iglesiaId)
            ->where('tipo_organizacion_id', Organizacion::TIPO_CLUB)
            ->where('estado_aprobacion', Organizacion::APROBACION_APROBADA)
            ->pluck('id');

        if ($orgIds->isEmpty()) {
            return [];
        }

        return Club::query()
            ->whereIn('organizacion_id', $orgIds)
            ->where('is_active', true)
            ->orderBy('nombre')
            ->get(['id', 'organizacion_id', 'nombre', 'nombre_corto'])
            ->map(fn (Club $club) => [
                'id' => (int) $club->id,
                'organizacion_id' => (int) $club->organizacion_id,
                'nombre' => $club->nombre,
                'nombre_corto' => $club->nombre_corto,
            ])
            ->values()
            ->all();
    }

    public function approve(Organizacion $organizacion, User $actor, ?string $observacion = null): Organizacion
    {
        $this->assertPendiente($organizacion);

        return DB::transaction(function () use ($organizacion, $actor, $observacion) {
            $lineage = $this->pendingLineage($organizacion);
            foreach ($lineage as $org) {
                $this->mark($org, Organizacion::APROBACION_APROBADA, $actor, $observacion);
                $this->activateClubAndUsers($org);
            }

            $this->auditLogger->log('organizaciones', 'aprobar', null, [
                'organizacion_id' => $organizacion->id,
            ], $organizacion);

            $fresh = $this->organizaciones->find($organizacion->id);
            $this->realtime->notify('updated', (int) $fresh->id);
            $this->notifyUsersOfOrgs($lineage);

            return $fresh;
        });
    }

    public function reject(Organizacion $organizacion, User $actor, ?string $observacion = null): Organizacion
    {
        $this->assertPendiente($organizacion);

        return DB::transaction(function () use ($organizacion, $actor, $observacion) {
            foreach ($this->pendingLineage($organizacion) as $org) {
                $this->mark($org, Organizacion::APROBACION_RECHAZADA, $actor, $observacion);
                $club = Club::query()->where('organizacion_id', $org->id)->first();
                if ($club) {
                    $club->update(['is_active' => false]);
                }
            }

            $this->auditLogger->log('organizaciones', 'rechazar', null, [
                'organizacion_id' => $organizacion->id,
            ], $organizacion);

            $fresh = $this->organizaciones->find($organizacion->id);
            $this->realtime->notify('updated', (int) $fresh->id);

            return $fresh;
        });
    }

    /**
     * @param  array{asociacion_id: int, distrito_id: int, iglesia_id: int, club_id?: int|null}  $destino
     */
    public function relocate(Organizacion $organizacion, User $actor, array $destino, ?string $observacion = null): Organizacion
    {
        $this->assertPendiente($organizacion);

        $iglesia = $this->approvedOrg((int) $destino['iglesia_id'], Organizacion::TIPO_IGLESIA);
        $distrito = $this->approvedOrg((int) $destino['distrito_id'], Organizacion::TIPO_DISTRITO);
        $asociacion = $this->approvedOrg((int) $destino['asociacion_id'], Organizacion::TIPO_ASOCIACION);

        if ((int) $distrito->organizacion_padre_id !== (int) $asociacion->id) {
            throw ValidationException::withMessages([
                'distrito_id' => ['El distrito no pertenece a la asociación seleccionada.'],
            ]);
        }
        if ((int) $iglesia->organizacion_padre_id !== (int) $distrito->id) {
            throw ValidationException::withMessages([
                'iglesia_id' => ['La iglesia no pertenece al distrito seleccionado.'],
            ]);
        }

        return DB::transaction(function () use ($organizacion, $actor, $destino, $iglesia, $observacion) {
            $pendingClubOrg = $this->findPendingClubOrg($organizacion);
            $lineage = $this->pendingLineage($organizacion);

            if (! empty($destino['club_id'])) {
                $targetClub = Club::query()->find((int) $destino['club_id']);
                if (! $targetClub || (int) $targetClub->organizacion?->organizacion_padre_id !== (int) $iglesia->id) {
                    $targetClub = Club::query()
                        ->where('id', (int) $destino['club_id'])
                        ->whereHas('organizacion', fn ($q) => $q->where('organizacion_padre_id', $iglesia->id))
                        ->first();
                }
                if (! $targetClub) {
                    throw ValidationException::withMessages([
                        'club_id' => ['El club seleccionado no pertenece a esa iglesia.'],
                    ]);
                }

                if ($pendingClubOrg) {
                    $this->moveUsersToClub($pendingClubOrg, $targetClub);
                }

                foreach ($lineage as $org) {
                    $this->mark($org, Organizacion::APROBACION_RECHAZADA, $actor, $observacion);
                    $club = Club::query()->where('organizacion_id', $org->id)->first();
                    if ($club) {
                        $club->update(['is_active' => false]);
                    }
                }

                $this->activateClubUsers($targetClub);
            } else {
                if (! $pendingClubOrg) {
                    throw ValidationException::withMessages([
                        'iglesia_id' => ['No hay un club pendiente para reubicar. Selecciona un club existente.'],
                    ]);
                }

                $pendingClubOrg->update(['organizacion_padre_id' => $iglesia->id]);
                $this->mark($pendingClubOrg, Organizacion::APROBACION_APROBADA, $actor, $observacion);
                $this->activateClubAndUsers($pendingClubOrg);

                foreach ($lineage as $org) {
                    if ((int) $org->id === (int) $pendingClubOrg->id) {
                        continue;
                    }
                    $this->mark($org, Organizacion::APROBACION_RECHAZADA, $actor, $observacion);
                }
            }

            $this->auditLogger->log('organizaciones', 'reubicar', null, [
                'organizacion_id' => $organizacion->id,
                'iglesia_id' => $iglesia->id,
                'club_id' => $destino['club_id'] ?? null,
            ], $organizacion);

            $fresh = $this->organizaciones->find($pendingClubOrg?->id ?? $organizacion->id);
            $this->realtime->notify('updated', (int) $fresh->id);
            $this->notifyUsersOfOrgs($lineage->concat(collect([$fresh])));

            return $fresh;
        });
    }

    /**
     * @param  Collection<int, Organizacion>  $orgs
     */
    private function notifyUsersOfOrgs(Collection $orgs): void
    {
        $orgIds = $orgs->pluck('id');
        $personaIds = PersonaOrganizacion::query()
            ->whereIn('organizacion_id', $orgIds)
            ->pluck('persona_id');

        User::query()->whereIn('persona_id', $personaIds)->get()->each(
            fn (User $user) => $this->accountMail->trySendApprovedNotice($user)
        );
    }

    private function assertPendiente(Organizacion $organizacion): void
    {
        if (! $organizacion->isPendiente()) {
            throw ValidationException::withMessages([
                'organizacion' => ['Solo se pueden revisar organizaciones pendientes.'],
            ]);
        }
    }

    private function approvedOrg(int $id, int $tipoId): Organizacion
    {
        $org = Organizacion::query()
            ->where('id', $id)
            ->where('tipo_organizacion_id', $tipoId)
            ->where('estado', true)
            ->where('estado_aprobacion', Organizacion::APROBACION_APROBADA)
            ->first();

        if (! $org) {
            throw ValidationException::withMessages([
                'destino' => ['La organización de destino no es válida o no está aprobada.'],
            ]);
        }

        return $org;
    }

    /**
     * @return Collection<int, Organizacion>
     */
    private function pendingLineage(Organizacion $organizacion): Collection
    {
        $items = collect([$organizacion]);

        $current = $organizacion;
        while ($current->organizacion_padre_id) {
            $parent = Organizacion::query()->find($current->organizacion_padre_id);
            if (! $parent || ! $parent->isPendiente()) {
                break;
            }
            $items->push($parent);
            $current = $parent;
        }

        $this->collectPendingDescendants($organizacion, $items);

        return $items->unique('id')->values();
    }

    /**
     * @param  Collection<int, Organizacion>  $items
     */
    private function collectPendingDescendants(Organizacion $organizacion, Collection $items): void
    {
        $children = Organizacion::query()
            ->where('organizacion_padre_id', $organizacion->id)
            ->where('estado_aprobacion', Organizacion::APROBACION_PENDIENTE)
            ->get();

        foreach ($children as $child) {
            if (! $items->contains(fn (Organizacion $org) => (int) $org->id === (int) $child->id)) {
                $items->push($child);
                $this->collectPendingDescendants($child, $items);
            }
        }
    }

    private function findPendingClubOrg(Organizacion $organizacion): ?Organizacion
    {
        if ((int) $organizacion->tipo_organizacion_id === Organizacion::TIPO_CLUB) {
            return $organizacion;
        }

        return $this->pendingLineage($organizacion)
            ->first(fn (Organizacion $org) => (int) $org->tipo_organizacion_id === Organizacion::TIPO_CLUB);
    }

    private function mark(Organizacion $organizacion, string $estado, User $actor, ?string $observacion): void
    {
        $organizacion->update([
            'estado_aprobacion' => $estado,
            'revision_observacion' => $observacion,
            'revisado_por' => $actor->id,
            'revisado_en' => now(),
        ]);
    }

    private function activateClubAndUsers(Organizacion $organizacion): void
    {
        $club = Club::query()->where('organizacion_id', $organizacion->id)->first();
        if (! $club) {
            return;
        }

        $club->update(['is_active' => true]);
        $this->activateClubUsers($club);
    }

    private function activateClubUsers(Club $club): void
    {
        if (! $club->organizacion_id) {
            return;
        }

        $personaIds = PersonaOrganizacion::query()
            ->where('organizacion_id', $club->organizacion_id)
            ->pluck('persona_id');

        User::query()->whereIn('persona_id', $personaIds)->update(['is_active' => true]);
    }

    private function moveUsersToClub(Organizacion $fromClubOrg, Club $targetClub): void
    {
        $fromClub = Club::query()->where('organizacion_id', $fromClubOrg->id)->first();
        if (! $fromClub || ! $targetClub->organizacion_id) {
            return;
        }

        $rows = PersonaOrganizacion::query()
            ->with('rolesAsignados.rol')
            ->where('organizacion_id', $fromClubOrg->id)
            ->get();

        foreach ($rows as $row) {
            $user = User::query()->where('persona_id', $row->persona_id)->first();
            foreach ($row->rolesAsignados as $assignment) {
                $position = Club::positionForRoleName((string) ($assignment->rol?->name ?? ''));
                if (! $position || ! $user) {
                    continue;
                }
                $this->clubService->syncDirectors($targetClub, [
                    $position => [
                        'mode' => 'select',
                        'user_id' => $user->id,
                        'persona_id' => $row->persona_id,
                    ],
                ], $user);
            }
        }
    }
}
