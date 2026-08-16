<?php

namespace App\Modules\Organizations\Services;

use App\Modules\Organizations\Events\OrganizacionChanged;

/**
 * Emite cambios de organización a clientes suscritos (opt-in en el front).
 * Seguro si Reverb no está disponible: no interrumpe el CRUD.
 */
final class OrganizacionRealtimeNotifier
{
    /**
     * @param  'created'|'updated'|'deleted'  $action
     */
    public function notify(string $action, int $organizacionId): void
    {
        try {
            event(new OrganizacionChanged($action, $organizacionId));
        } catch (\Throwable) {
            // El CRUD no debe fallar si el broadcast no está disponible.
        }
    }
}
