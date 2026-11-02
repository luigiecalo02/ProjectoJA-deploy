<?php

namespace App\Modules\Notifications\Services;

use Illuminate\Support\Facades\DB;

final class ReadNotificationPruner
{
    /**
     * Elimina notificaciones ya leídas según NOTIFICATIONS_READ_RETENTION_DAYS.
     *
     * @return int Filas eliminadas (0 si la limpieza está desactivada).
     */
    public function prune(): int
    {
        $days = (int) config('club_notifications.read_retention_days', 7);
        if ($days < 0) {
            return 0;
        }

        $query = DB::table('notifications')->whereNotNull('read_at');

        if ($days > 0) {
            $query->where('read_at', '<', now()->subDays($days));
        }

        return $query->delete();
    }
}
