<?php

namespace App\Modules\Notifications\Services;

use App\Models\User;
use App\Modules\Events\Models\Event;
use App\Modules\Events\Models\EventoParticipacionAbono;
use App\Modules\Notifications\Enums\ClubNotificationType;
use App\Modules\Notifications\Notifications\AbonoAprobadoNotification;
use App\Modules\Notifications\Notifications\AsistenciaMarcadaNotification;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;

final class ClubNotifier
{
    public function __construct(
        private readonly NotificationPreferenceService $preferences,
        private readonly RecipientResolver $recipients,
    ) {}

    public function asistenciaMarcada(Event $event, int $personaId, string $estado, ?User $actor = null): void
    {
        $orgId = (int) ($event->organizacion_id ?? 0);
        if ($orgId <= 0 || $personaId <= 0) {
            return;
        }

        $this->dispatchAfterCommit(function () use ($event, $personaId, $estado, $actor, $orgId) {
            if (! $this->preferences->isEnabled($orgId, ClubNotificationType::ASISTENCIA_MARCADA)) {
                return;
            }

            $users = $this->recipients->usersForPersona($personaId);
            if ($actor) {
                $users = $users->reject(fn (User $user) => (int) $user->id === (int) $actor->id);
            }
            if ($users->isEmpty()) {
                return;
            }

            Notification::send($users, new AsistenciaMarcadaNotification($event, $estado));
        });
    }

    public function abonoAprobado(EventoParticipacionAbono $abono, ?User $actor = null): void
    {
        $orgId = (int) ($abono->organizacion_id ?? 0);
        $personaId = (int) ($abono->persona_id ?? 0);
        if ($orgId <= 0 || $personaId <= 0) {
            return;
        }

        $this->dispatchAfterCommit(function () use ($abono, $actor, $orgId, $personaId) {
            if (! $this->preferences->isEnabled($orgId, ClubNotificationType::ABONO_APROBADO)) {
                return;
            }

            $users = $this->recipients->usersForPersona($personaId);
            if ($actor) {
                $users = $users->reject(fn (User $user) => (int) $user->id === (int) $actor->id);
            }
            if ($users->isEmpty()) {
                return;
            }

            Notification::send($users, new AbonoAprobadoNotification($abono));
        });
    }

    private function dispatchAfterCommit(callable $callback): void
    {
        if (DB::transactionLevel() > 0) {
            DB::afterCommit($callback);

            return;
        }

        $callback();
    }
}
