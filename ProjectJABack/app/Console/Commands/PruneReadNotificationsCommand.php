<?php

namespace App\Console\Commands;

use App\Modules\Notifications\Services\ReadNotificationPruner;
use Illuminate\Console\Command;

class PruneReadNotificationsCommand extends Command
{
    protected $signature = 'notifications:prune-read';

    protected $description = 'Elimina notificaciones leídas según NOTIFICATIONS_READ_RETENTION_DAYS';

    public function handle(ReadNotificationPruner $pruner): int
    {
        $days = (int) config('club_notifications.read_retention_days', 7);
        if ($days < 0) {
            $this->warn('Limpieza desactivada (NOTIFICATIONS_READ_RETENTION_DAYS < 0).');

            return self::SUCCESS;
        }

        $deleted = $pruner->prune();
        $this->info($days === 0
            ? "Notificaciones leídas eliminadas: {$deleted}."
            : "Notificaciones leídas con más de {$days} día(s) eliminadas: {$deleted}.");

        return self::SUCCESS;
    }
}
