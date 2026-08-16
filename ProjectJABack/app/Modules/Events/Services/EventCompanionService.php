<?php

namespace App\Modules\Events\Services;

use App\Models\User;
use App\Modules\Clubs\Models\Persona;
use App\Modules\Events\Models\Event;
use App\Modules\Shared\Services\AuditLogger;
use Illuminate\Support\Collection;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

final class EventCompanionService
{
    public function __construct(
        private readonly EventParticipationService $participation,
        private readonly AuditLogger $auditLogger,
    ) {}

    /**
     * Busca en el catálogo global de personas, sin limitar por organización.
     *
     * @return Collection<int, Persona>
     */
    public function search(User $actor, Event $event, string $term, int $limit = 20): Collection
    {
        $this->assertCanManage($actor, $event);
        $term = trim($term);

        if (mb_strlen($term) < 3) {
            return collect();
        }

        return Persona::query()
            ->where(function ($query) use ($term) {
                $query->where('identificacion', 'like', "%{$term}%")
                    ->orWhere('nombre1', 'like', "%{$term}%")
                    ->orWhere('nombre2', 'like', "%{$term}%")
                    ->orWhere('apellido1', 'like', "%{$term}%")
                    ->orWhere('apellido2', 'like', "%{$term}%");
            })
            ->orderBy('apellido1')
            ->orderBy('nombre1')
            ->limit(max(1, min($limit, 50)))
            ->get();
    }

    /**
     * Crea una persona global para usarla como acompañante, sin asociarla a organizaciones.
     *
     * @param  array<string, mixed>  $data
     */
    public function create(User $actor, Event $event, array $data): Persona
    {
        $this->assertCanManage($actor, $event);
        $persona = Persona::query()->create($data);
        $this->auditLogger->log('personas', 'create_event_companion', null, $persona->toArray(), $persona);

        return $persona;
    }

    private function assertCanManage(User $actor, Event $event): void
    {
        $this->participation->assertClubDirectorContext($actor);
        if (! $actor->can('view', $event)) {
            throw new AccessDeniedHttpException('No puedes gestionar acompañantes para este evento.');
        }
    }
}
