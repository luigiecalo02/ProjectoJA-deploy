<?php

return [
    /*
     * Organización raíz de este front Clubes. Si está definido, el login con
     * X-Clubes-Client solo admite esa organización, sus hijas o un superadmin.
     * Si está vacío, se usa el header X-Clubes-Root-Id que envía el front.
     */
    'root_organizacion_id' => env('CLUBES_ROOT_ORGANIZACION_ID', env('CLUB_ID')),
];
