<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    private const ASOCIACION = 'Asociación del Caribe Colombiano';

    private const TIPO_ASOCIACION = 2;

    private const TIPO_DISTRITO = 3;

    private const TIPO_IGLESIA = 4;

    private const TIPO_CLUB = 5;

    private const TIPO_ZONA = 9;

    public function up(): void
    {
        $asociacion = $this->asociacion();
        if (! $asociacion) {
            throw new \RuntimeException('No existe la Asociación "'.self::ASOCIACION.'".');
        }

        $now = now();

        foreach ($this->arbol() as $zonaData) {
            $zona = DB::table('organizacion')
                ->where('organizacion_padre_id', $asociacion->id)
                ->where('tipo_organizacion_id', self::TIPO_ZONA)
                ->where('nombre', $zonaData['nombre'])
                ->first();
            if (! $zona) {
                throw new \RuntimeException('Falta la zona "'.$zonaData['nombre'].'".');
            }

            foreach ($zonaData['distritos'] as $distritoData) {
                $distrito = DB::table('organizacion')
                    ->where('organizacion_padre_id', $zona->id)
                    ->where('tipo_organizacion_id', self::TIPO_DISTRITO)
                    ->where('nombre', $distritoData['nombre'])
                    ->first();
                if (! $distrito) {
                    throw new \RuntimeException(
                        'Falta el distrito "'.$distritoData['nombre'].'" en '.$zonaData['nombre']
                    );
                }

                $nombresIglesia = array_column($distritoData['iglesias'], 'nombre');

                foreach ($distritoData['iglesias'] as $iglesiaData) {
                    $iglesiaId = $this->upsertOrganizacion([
                        'padre_id' => (int) $distrito->id,
                        'tipo_id' => self::TIPO_IGLESIA,
                        'nombre' => $iglesiaData['nombre'],
                        'pais_id' => $distrito->pais_id,
                        'departamento_id' => $distrito->departamento_id,
                        'ciudad_id' => $distrito->ciudad_id,
                        'direccion' => 'Por definir',
                        'codigo_padre' => $distrito->codigo,
                        'prefijo' => 'IGL',
                    ], $now);

                    $iglesia = DB::table('organizacion')->where('id', $iglesiaId)->first();

                    foreach ($iglesiaData['clubes'] as $nombreClub) {
                        $clubOrgId = $this->reubicarOCrearClub(
                            (int) $distrito->id,
                            $iglesiaId,
                            $nombreClub,
                            $iglesia,
                            $distritoData['nombre'],
                            $now,
                        );
                        $this->upsertClub($clubOrgId, $nombreClub, $distritoData['nombre'], $now);
                    }
                }

                $this->borrarIglesiaContenedor((int) $distrito->id, $distritoData['nombre'], $nombresIglesia);
            }
        }
    }

    public function down(): void
    {
        $asociacion = $this->asociacion();
        if (! $asociacion) {
            return;
        }

        foreach ($this->arbol() as $zonaData) {
            $zona = DB::table('organizacion')
                ->where('organizacion_padre_id', $asociacion->id)
                ->where('tipo_organizacion_id', self::TIPO_ZONA)
                ->where('nombre', $zonaData['nombre'])
                ->first();
            if (! $zona) {
                continue;
            }

            foreach ($zonaData['distritos'] as $distritoData) {
                $distrito = DB::table('organizacion')
                    ->where('organizacion_padre_id', $zona->id)
                    ->where('tipo_organizacion_id', self::TIPO_DISTRITO)
                    ->where('nombre', $distritoData['nombre'])
                    ->first();
                if (! $distrito) {
                    continue;
                }

                foreach ($distritoData['iglesias'] as $iglesiaData) {
                    $iglesia = DB::table('organizacion')
                        ->where('organizacion_padre_id', $distrito->id)
                        ->where('tipo_organizacion_id', self::TIPO_IGLESIA)
                        ->where('nombre', $iglesiaData['nombre'])
                        ->first();
                    if (! $iglesia) {
                        continue;
                    }

                    $clubOrgIds = DB::table('organizacion')
                        ->where('organizacion_padre_id', $iglesia->id)
                        ->where('tipo_organizacion_id', self::TIPO_CLUB)
                        ->whereIn('nombre', $iglesiaData['clubes'])
                        ->pluck('id');
                    DB::table('clubes')->whereIn('organizacion_id', $clubOrgIds)->delete();
                    DB::table('organizacion')->whereIn('id', $clubOrgIds)->delete();
                    DB::table('organizacion')->where('id', $iglesia->id)->delete();
                }
            }
        }
    }

    private function asociacion(): ?object
    {
        return DB::table('organizacion')
            ->where('tipo_organizacion_id', self::TIPO_ASOCIACION)
            ->where('nombre', self::ASOCIACION)
            ->first();
    }

    /**
     * @param  list<string>  $nombresIglesiaReales
     */
    private function borrarIglesiaContenedor(int $distritoId, string $nombreDistrito, array $nombresIglesiaReales): void
    {
        if (in_array($nombreDistrito, $nombresIglesiaReales, true)) {
            return;
        }

        $contenedor = DB::table('organizacion')
            ->where('organizacion_padre_id', $distritoId)
            ->where('tipo_organizacion_id', self::TIPO_IGLESIA)
            ->where('nombre', $nombreDistrito)
            ->first();
        if (! $contenedor) {
            return;
        }

        $hijos = DB::table('organizacion')->where('organizacion_padre_id', $contenedor->id)->count();
        if ($hijos > 0) {
            return;
        }

        DB::table('organizacion')->where('id', $contenedor->id)->delete();
    }

    private function reubicarOCrearClub(
        int $distritoId,
        int $iglesiaId,
        string $nombreClub,
        object $iglesia,
        string $nombreDistrito,
        mixed $now,
    ): int {
        $iglesiasDelDistrito = DB::table('organizacion')
            ->where('organizacion_padre_id', $distritoId)
            ->where('tipo_organizacion_id', self::TIPO_IGLESIA)
            ->pluck('id');

        $existente = DB::table('organizacion')
            ->whereIn('organizacion_padre_id', $iglesiasDelDistrito)
            ->where('tipo_organizacion_id', self::TIPO_CLUB)
            ->where('nombre', $nombreClub)
            ->first();

        if ($existente) {
            DB::table('organizacion')->where('id', $existente->id)->update([
                'organizacion_padre_id' => $iglesiaId,
                'pais_id' => $iglesia->pais_id,
                'departamento_id' => $iglesia->departamento_id,
                'ciudad_id' => $iglesia->ciudad_id,
                'fecha_actualizacion' => $now,
            ]);

            return (int) $existente->id;
        }

        return $this->upsertOrganizacion([
            'padre_id' => $iglesiaId,
            'tipo_id' => self::TIPO_CLUB,
            'nombre' => $nombreClub,
            'pais_id' => $iglesia->pais_id,
            'departamento_id' => $iglesia->departamento_id,
            'ciudad_id' => $iglesia->ciudad_id,
            'direccion' => null,
            'codigo_padre' => $iglesia->codigo,
            'prefijo' => 'CLB',
        ], $now);
    }

    /**
     * @param  array{
     *     padre_id: int,
     *     tipo_id: int,
     *     nombre: string,
     *     pais_id: mixed,
     *     departamento_id: mixed,
     *     ciudad_id: mixed,
     *     direccion: ?string,
     *     codigo_padre: mixed,
     *     prefijo: string
     * }  $data
     */
    private function upsertOrganizacion(array $data, mixed $now): int
    {
        $existente = DB::table('organizacion')
            ->where('organizacion_padre_id', $data['padre_id'])
            ->where('tipo_organizacion_id', $data['tipo_id'])
            ->where('nombre', $data['nombre'])
            ->first();

        $payload = [
            'organizacion_padre_id' => $data['padre_id'],
            'tipo_organizacion_id' => $data['tipo_id'],
            'pais_id' => $data['pais_id'],
            'departamento_id' => $data['departamento_id'],
            'ciudad_id' => $data['ciudad_id'],
            'nombre' => $data['nombre'],
            'direccion' => $data['direccion'],
            'estado' => true,
            'estado_aprobacion' => 'aprobada',
            'fecha_actualizacion' => $now,
        ];

        if ($existente) {
            DB::table('organizacion')->where('id', $existente->id)->update($payload);

            return (int) $existente->id;
        }

        return (int) DB::table('organizacion')->insertGetId(array_merge($payload, [
            'codigo' => $this->siguienteCodigo($data['codigo_padre'] ? (string) $data['codigo_padre'] : null, $data['prefijo']),
            'fecha_creacion' => $now,
        ]));
    }

    private function upsertClub(int $organizacionId, string $nombre, string $distrito, mixed $now): void
    {
        $existente = DB::table('clubes')->where('organizacion_id', $organizacionId)->first();
        $payload = [
            'organizacion_id' => $organizacionId,
            'nombre' => $nombre,
            'distrito' => $distrito,
            'tipos' => json_encode(['conquistadores']),
            'is_active' => true,
            'updated_at' => $now,
        ];

        if ($existente) {
            DB::table('clubes')->where('id', $existente->id)->update($payload);

            return;
        }

        DB::table('clubes')->insert(array_merge($payload, ['created_at' => $now]));
    }

    /**
     * @return list<array{nombre: string, distritos: list<array{nombre: string, iglesias: list<array{nombre: string, clubes: list<string>}>}>}>
     */
    private function arbol(): array
    {
        return [
            [
                'nombre' => 'Zona Cartagena',
                'distritos' => [
                    ['nombre' => 'EBENEZER', 'iglesias' => [
                        ['nombre' => 'LEONES DE JUDÁ', 'clubes' => ['LEONES DE JUDÁ']],
                        ['nombre' => 'ESTRELLA DEL ESTE', 'clubes' => ['ESTRELLA DEL ESTE']],
                    ]],
                    ['nombre' => 'MARANATHA', 'iglesias' => [
                        ['nombre' => 'SEBAOTH ELOE JUNIOR', 'clubes' => ['SEBAOTH ELOE JUNIOR']],
                        ['nombre' => 'JIREH REDENCIÓN TEENS', 'clubes' => ['JIREH REDENCIÓN TEENS']],
                        ['nombre' => 'MARANATHA HEROICA', 'clubes' => ['MARANATHA HEROICA']],
                        ['nombre' => 'ALEF - TAV', 'clubes' => ['ALEF - TAV']],
                    ]],
                    ['nombre' => 'EMAUS', 'iglesias' => [
                        ['nombre' => 'HAYTHAM BARZILAI', 'clubes' => ['HAYTHAM BARZILAI']],
                        ['nombre' => 'HOVAZ JESHUA', 'clubes' => ['HOVAZ JESHUA']],
                    ]],
                    ['nombre' => 'NORTE', 'iglesias' => [
                        ['nombre' => 'DEL SHADDAY', 'clubes' => ['DEL SHADDAY']],
                        ['nombre' => 'ESTRELLA DE LA MAÑANA', 'clubes' => ['ESTRELLA DE LA MAÑANA']],
                        ['nombre' => 'DIVINO PASTOR', 'clubes' => ['DIVINO PASTOR']],
                    ]],
                    ['nombre' => 'COLEGIO ADVENTISTA CTG', 'iglesias' => [
                        ['nombre' => 'ADULAM', 'clubes' => ['ADULAM']],
                    ]],
                    ['nombre' => 'CENTRAL CTG', 'iglesias' => [
                        ['nombre' => 'SHALOM', 'clubes' => ['SHALOM']],
                        ['nombre' => 'RIC', 'clubes' => ['RIC']],
                        ['nombre' => 'AGIOS', 'clubes' => ['AGIOS']],
                    ]],
                    ['nombre' => 'BETANIA', 'iglesias' => [
                        ['nombre' => 'DUNAMIS', 'clubes' => ['DUNAMIS']],
                        ['nombre' => 'ALFA SURIEL', 'clubes' => ['ALFA SURIEL']],
                    ]],
                    ['nombre' => 'ORIENTAL', 'iglesias' => [
                        ['nombre' => 'YAHVE YIREH', 'clubes' => ['YAHVE YIREH']],
                        ['nombre' => 'MAHANAIM', 'clubes' => ['MAHANAIM']],
                        ['nombre' => 'EMMANUEL', 'clubes' => ['EMMANUEL']],
                    ]],
                ],
            ],
            [
                'nombre' => 'Zona de la Mojana',
                'distritos' => [
                    ['nombre' => 'ACHI', 'iglesias' => [
                        ['nombre' => 'BARKLAY', 'clubes' => ['BARKLAY']],
                    ]],
                    ['nombre' => 'MAJAGUAL', 'iglesias' => [
                        ['nombre' => 'FUSION MAJAGUAL', 'clubes' => ['FUSION MAJAGUAL']],
                    ]],
                    ['nombre' => 'GUARANDA', 'iglesias' => [
                        ['nombre' => 'JADÁ', 'clubes' => ['JADÁ']],
                        ['nombre' => 'MALAKH', 'clubes' => ['MALAKH']],
                    ]],
                ],
            ],
            [
                'nombre' => 'Zona de la Sabana',
                'distritos' => [
                    ['nombre' => 'SAN SEBASTIAN', 'iglesias' => [
                        ['nombre' => 'JADEV', 'clubes' => ['JADEV']],
                        ['nombre' => 'HERALDOS DE SHALOM', 'clubes' => ['HERALDOS DE SHALOM']],
                    ]],
                    ['nombre' => 'CENTRAL SINCELEJO', 'iglesias' => [
                        ['nombre' => 'SEMINI', 'clubes' => ['SEMINI']],
                        ['nombre' => 'SIERVOS DE JESÚS JUNIOR', 'clubes' => ['SIERVOS DE JESÚS JUNIOR']],
                    ]],
                    ['nombre' => 'SINCELEJO', 'iglesias' => [
                        ['nombre' => 'ZURIEL', 'clubes' => ['ZURIEL']],
                        ['nombre' => 'BARUCHIDAY JUNIOR', 'clubes' => ['BARUCHIDAY JUNIOR']],
                    ]],
                    ['nombre' => 'MAGANGUÉ', 'iglesias' => [
                        ['nombre' => 'PORTALUZ', 'clubes' => ['PORTALUZ']],
                    ]],
                    ['nombre' => 'HOREB-SAHAGÚN', 'iglesias' => [
                        ['nombre' => 'SHELIAJ', 'clubes' => ['SHELIAJ']],
                    ]],
                ],
            ],
            [
                'nombre' => 'Zona de Montería',
                'distritos' => [
                    ['nombre' => 'MONTERÍA OCCIDENTAL', 'iglesias' => [
                        ['nombre' => 'PENIEL DEL RIO', 'clubes' => ['PENIEL DEL RIO']],
                    ]],
                    ['nombre' => 'MONTERÍA CENTRAL', 'iglesias' => [
                        ['nombre' => 'ALMAGOR', 'clubes' => ['ALMAGOR']],
                    ]],
                    ['nombre' => 'SAN JORGE', 'iglesias' => [
                        ['nombre' => 'BERESHIT', 'clubes' => ['BERESHIT']],
                        ['nombre' => 'GUERREROS DE FE', 'clubes' => ['GUERREROS DE FE']],
                    ]],
                    ['nombre' => 'BAJO SINÚ', 'iglesias' => [
                        ['nombre' => 'GENERACIÓN CALEB', 'clubes' => ['GENERACIÓN CALEB']],
                    ]],
                    ['nombre' => 'SINÚ CENTRAL', 'iglesias' => [
                        ['nombre' => 'ROCA DEL SINÚ', 'clubes' => ['ROCA DEL SINÚ']],
                    ]],
                    ['nombre' => 'CÓRDOBA NORTE', 'iglesias' => [
                        ['nombre' => 'JEHIEL', 'clubes' => ['JEHIEL']],
                    ]],
                ],
            ],
            [
                'nombre' => 'Zona Montes de María',
                'distritos' => [
                    ['nombre' => 'MONTES DE MARÍA', 'iglesias' => [
                        ['nombre' => 'GEMA', 'clubes' => ['GEMA']],
                        ['nombre' => 'ÁNGELES DE JESÚS', 'clubes' => ['ÁNGELES DE JESÚS']],
                        ['nombre' => 'BOANERGES JUNIOR', 'clubes' => ['BOANERGES JUNIOR']],
                        ['nombre' => 'ADONAI', 'clubes' => ['ADONAI']],
                    ]],
                    ['nombre' => 'PLATO', 'iglesias' => [
                        ['nombre' => 'ADAEL', 'clubes' => ['ADAEL']],
                        ['nombre' => 'HOREB', 'clubes' => ['HOREB']],
                        ['nombre' => 'SHEKINA', 'clubes' => ['SHEKINA']],
                        ['nombre' => 'GERIZIM', 'clubes' => ['GERIZIM']],
                    ]],
                    ['nombre' => 'CARMEN DE BOLÍVAR', 'iglesias' => [
                        ['nombre' => 'EMBAJADORES DE CRISTO', 'clubes' => ['EMBAJADORES DE CRISTO']],
                        ['nombre' => 'ELLEN WHITE', 'clubes' => ['ELLEN WHITE']],
                    ]],
                ],
            ],
            [
                'nombre' => 'Zona Elitur',
                'distritos' => [
                    ['nombre' => 'ELIMELEC', 'iglesias' => [
                        ['nombre' => 'CANAAN', 'clubes' => ['CANAAN']],
                    ]],
                    ['nombre' => 'TURBACO', 'iglesias' => [
                        ['nombre' => 'VALDENSES', 'clubes' => ['VALDENSES']],
                        ['nombre' => 'SINAÍ TEENS', 'clubes' => ['SINAÍ TEENS']],
                    ]],
                ],
            ],
            [
                'nombre' => 'Zona del Alto Sinú',
                'distritos' => [
                    ['nombre' => 'TIERRALTA OCCIDENTAL', 'iglesias' => [
                        ['nombre' => 'SHARAT', 'clubes' => ['SHARAT']],
                    ]],
                    ['nombre' => 'TIERRALTA', 'iglesias' => [
                        ['nombre' => 'FORTALEZA', 'clubes' => ['FORTALEZA']],
                    ]],
                    ['nombre' => 'VALENCIA', 'iglesias' => [
                        ['nombre' => 'ALFA CENTAURO', 'clubes' => ['ALFA CENTAURO']],
                    ]],
                    ['nombre' => 'TIERRALTA CENTRAL', 'iglesias' => [
                        ['nombre' => 'BERESHIT', 'clubes' => ['BERESHIT']],
                        ['nombre' => 'ORIEL', 'clubes' => ['ORIEL']],
                    ]],
                ],
            ],
        ];
    }

    private function siguienteCodigo(?string $padreCodigo, string $prefix): string
    {
        $base = $padreCodigo ? $padreCodigo.'-'.$prefix.'-' : $prefix.'-';
        $pad = $padreCodigo ? 2 : 4;
        $existentes = DB::table('organizacion')->where('codigo', 'like', $base.'%')->pluck('codigo');
        $max = 0;
        foreach ($existentes as $codigo) {
            if (preg_match('/^'.preg_quote($base, '/').'(\d+)$/', (string) $codigo, $matches)) {
                $max = max($max, (int) $matches[1]);
            }
        }
        $next = $max + 1;
        do {
            $candidate = $base.str_pad((string) $next, $pad, '0', STR_PAD_LEFT);
            $exists = DB::table('organizacion')->where('codigo', $candidate)->exists();
            $next++;
        } while ($exists);

        return $candidate;
    }
};
