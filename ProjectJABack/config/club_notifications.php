<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Retención de notificaciones leídas
    |--------------------------------------------------------------------------
    |
    | Días que se conservan las notificaciones con read_at antes de borrarlas.
    | 0 = borrar todas las leídas en cuanto corre el job.
    | <0 = desactivar la limpieza.
    |
    */
    'read_retention_days' => (int) env('NOTIFICATIONS_READ_RETENTION_DAYS', 7),
];
