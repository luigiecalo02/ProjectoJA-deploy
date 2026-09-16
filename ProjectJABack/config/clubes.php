<?php

return [
    /*
     * Organización raíz de este front Clubes. Si está definido, el login con
     * X-Clubes-Client solo admite esa organización, sus hijas o un superadmin.
     * Si está vacío, se usa el header X-Clubes-Root-Id que envía el front.
     * El mapa CLUBES_HOSTS gana sobre este valor cuando el Origin/Host coincide.
     */
    'root_organizacion_id' => env('CLUBES_ROOT_ORGANIZACION_ID', env('CLUB_ID')),

    /*
     * Mapa host:organizacion_id (coma-separado). Se resuelve con Origin, Referer
     * o Host. Ejemplo:
     * guias.clubric.online:10,aventureros.clubric.online:11,conquistadores.clubric.online:12
     */
    'hosts' => env('CLUBES_HOSTS', ''),
];
