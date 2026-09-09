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
        $asociacion = DB::table('organizacion')
            ->where('tipo_organizacion_id', self::TIPO_ASOCIACION)
            ->where('nombre', self::ASOCIACION)
            ->first();
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
                throw new \RuntimeException('Falta la zona "'.$zonaData['nombre'].'". Corre antes la migración de zonas.');
            }

            if (! empty($zonaData['municipios'])) {
                $ciudades = $this->resolverCiudades($zonaData['municipios']);
                $departamentoIds = $this->uniqueIds($ciudades, 'departamento_id');
                $ciudadIds = $this->uniqueIds($ciudades, 'id');
                $primera = $ciudades[0];
                DB::table('organizacion')->where('id', $zona->id)->update([
                    'departamento_id' => $primera->departamento_id,
                    'ciudad_id' => $primera->id,
                    'fecha_actualizacion' => $now,
                ]);
                $this->syncPivot('organizacion_departamento', 'departamento_id', (int) $zona->id, $departamentoIds, $now);
                $this->syncPivot('organizacion_ciudad', 'ciudad_id', (int) $zona->id, $ciudadIds, $now);
                $zona = DB::table('organizacion')->where('id', $zona->id)->first();
            }

            if (! $zona->departamento_id || ! $zona->ciudad_id) {
                throw new \RuntimeException('La zona "'.$zona->nombre.'" no tiene municipio asignado.');
            }

            foreach ($zonaData['distritos'] as $distritoData) {
                $distritoId = $this->upsertOrganizacion([
                    'padre_id' => (int) $zona->id,
                    'tipo_id' => self::TIPO_DISTRITO,
                    'nombre' => $distritoData['nombre'],
                    'pais_id' => $zona->pais_id,
                    'departamento_id' => $zona->departamento_id,
                    'ciudad_id' => $zona->ciudad_id,
                    'direccion' => null,
                    'codigo_padre' => $zona->codigo,
                    'prefijo' => 'DIS',
                ], $now);

                $iglesiaId = $this->upsertOrganizacion([
                    'padre_id' => $distritoId,
                    'tipo_id' => self::TIPO_IGLESIA,
                    'nombre' => $distritoData['nombre'],
                    'pais_id' => $zona->pais_id,
                    'departamento_id' => $zona->departamento_id,
                    'ciudad_id' => $zona->ciudad_id,
                    'direccion' => 'Por definir',
                    'codigo_padre' => DB::table('organizacion')->where('id', $distritoId)->value('codigo'),
                    'prefijo' => 'IGL',
                ], $now);

                $iglesia = DB::table('organizacion')->where('id', $iglesiaId)->first();

                foreach ($distritoData['clubes'] as $nombreClub) {
                    $clubOrgId = $this->upsertOrganizacion([
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
                    $this->upsertClub($clubOrgId, $nombreClub, $distritoData['nombre'], $now);
                }
            }
        }
    }

    public function down(): void
    {
        $asociacion = DB::table('organizacion')
            ->where('tipo_organizacion_id', self::TIPO_ASOCIACION)
            ->where('nombre', self::ASOCIACION)
            ->first();
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

                $iglesia = DB::table('organizacion')
                    ->where('organizacion_padre_id', $distrito->id)
                    ->where('tipo_organizacion_id', self::TIPO_IGLESIA)
                    ->where('nombre', $distritoData['nombre'])
                    ->first();

                if ($iglesia) {
                    $clubOrgIds = DB::table('organizacion')
                        ->where('organizacion_padre_id', $iglesia->id)
                        ->where('tipo_organizacion_id', self::TIPO_CLUB)
                        ->whereIn('nombre', $distritoData['clubes'])
                        ->pluck('id');
                    DB::table('clubes')->whereIn('organizacion_id', $clubOrgIds)->delete();
                    DB::table('organizacion')->whereIn('id', $clubOrgIds)->delete();
                    DB::table('organizacion')->where('id', $iglesia->id)->delete();
                }

                DB::table('organizacion')->where('id', $distrito->id)->delete();
            }
        }
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
     * @return list<array{nombre: string, municipios?: list<array{departamento: string, nombre: string}>, distritos: list<array{nombre: string, clubes: list<string>}>}>
     */
    private function arbol(): array
    {
        return [
            [
                'nombre' => 'Zona Cartagena',
                'distritos' => [
                    ['nombre' => 'EBENEZER', 'clubes' => ['LEONES DE JUDÁ', 'ESTRELLA DEL ESTE']],
                    ['nombre' => 'MARANATHA', 'clubes' => ['SEBAOTH ELOE JUNIOR', 'JIREH REDENCIÓN TEENS', 'MARANATHA HEROICA', 'ALEF - TAV']],
                    ['nombre' => 'EMAUS', 'clubes' => ['HAYTHAM BARZILAI', 'HOVAZ JESHUA']],
                    ['nombre' => 'NORTE', 'clubes' => ['DEL SHADDAY', 'ESTRELLA DE LA MAÑANA', 'DIVINO PASTOR']],
                    ['nombre' => 'COLEGIO ADVENTISTA CTG', 'clubes' => ['ADULAM']],
                    ['nombre' => 'CENTRAL CTG', 'clubes' => ['SHALOM', 'RIC', 'AGIOS']],
                    ['nombre' => 'BETANIA', 'clubes' => ['DUNAMIS', 'ALFA SURIEL']],
                    ['nombre' => 'ORIENTAL', 'clubes' => ['YAHVE YIREH', 'MAHANAIM', 'EMMANUEL']],
                ],
            ],
            [
                'nombre' => 'Zona de la Mojana',
                'distritos' => [
                    ['nombre' => 'ACHI', 'clubes' => ['BARKLAY']],
                    ['nombre' => 'MAJAGUAL', 'clubes' => ['FUSION MAJAGUAL']],
                    ['nombre' => 'GUARANDA', 'clubes' => ['JADÁ', 'MALAKH']],
                ],
            ],
            [
                'nombre' => 'Zona de la Sabana',
                'distritos' => [
                    ['nombre' => 'SAN SEBASTIAN', 'clubes' => ['JADEV', 'HERALDOS DE SHALOM']],
                    ['nombre' => 'CENTRAL SINCELEJO', 'clubes' => ['SEMINI', 'SIERVOS DE JESÚS JUNIOR']],
                    ['nombre' => 'SINCELEJO', 'clubes' => ['ZURIEL', 'BARUCHIDAY JUNIOR']],
                    ['nombre' => 'MAGANGUÉ', 'clubes' => ['PORTALUZ']],
                    ['nombre' => 'HOREB-SAHAGÚN', 'clubes' => ['SHELIAJ']],
                ],
            ],
            [
                'nombre' => 'Zona de Montería',
                'distritos' => [
                    ['nombre' => 'MONTERÍA OCCIDENTAL', 'clubes' => ['PENIEL DEL RIO']],
                    ['nombre' => 'MONTERÍA CENTRAL', 'clubes' => ['ALMAGOR']],
                    ['nombre' => 'SAN JORGE', 'clubes' => ['BERESHIT', 'GUERREROS DE FE']],
                    ['nombre' => 'BAJO SINÚ', 'clubes' => ['GENERACIÓN CALEB']],
                    ['nombre' => 'SINÚ CENTRAL', 'clubes' => ['ROCA DEL SINÚ']],
                    ['nombre' => 'CÓRDOBA NORTE', 'clubes' => ['JEHIEL']],
                ],
            ],
            [
                'nombre' => 'Zona Montes de María',
                'distritos' => [
                    ['nombre' => 'MONTES DE MARÍA', 'clubes' => ['GEMA', 'ÁNGELES DE JESÚS', 'BOANERGES JUNIOR', 'ADONAI']],
                    ['nombre' => 'PLATO', 'clubes' => ['ADAEL', 'HOREB', 'SHEKINA', 'GERIZIM']],
                    ['nombre' => 'CARMEN DE BOLÍVAR', 'clubes' => ['EMBAJADORES DE CRISTO', 'ELLEN WHITE']],
                ],
            ],
            [
                'nombre' => 'Zona Elitur',
                'municipios' => [
                    ['departamento' => 'Bolívar', 'nombre' => 'Turbaco'],
                ],
                'distritos' => [
                    ['nombre' => 'ELIMELEC', 'clubes' => ['CANAAN']],
                    ['nombre' => 'TURBACO', 'clubes' => ['VALDENSES', 'SINAÍ TEENS']],
                ],
            ],
            [
                'nombre' => 'Zona del Alto Sinú',
                'distritos' => [
                    ['nombre' => 'TIERRALTA OCCIDENTAL', 'clubes' => ['SHARAT']],
                    ['nombre' => 'TIERRALTA', 'clubes' => ['FORTALEZA']],
                    ['nombre' => 'VALENCIA', 'clubes' => ['ALFA CENTAURO']],
                    ['nombre' => 'TIERRALTA CENTRAL', 'clubes' => ['BERESHIT', 'ORIEL']],
                ],
            ],
        ];
    }

    /**
     * @param  list<array{departamento: string, nombre: string}>  $municipios
     * @return list<object>
     */
    private function resolverCiudades(array $municipios): array
    {
        $encontradas = [];
        foreach ($municipios as $municipio) {
            $departamento = DB::table('departamento')
                ->whereRaw('LOWER(nombre) = ?', [mb_strtolower($municipio['departamento'])])
                ->first();
            if (! $departamento) {
                throw new \RuntimeException('No existe el departamento: '.$municipio['departamento']);
            }
            $ciudad = DB::table('ciudad')
                ->where('departamento_id', $departamento->id)
                ->whereRaw('LOWER(nombre) = ?', [mb_strtolower($municipio['nombre'])])
                ->first();
            if (! $ciudad) {
                throw new \RuntimeException('No existe el municipio "'.$municipio['nombre'].'" en '.$municipio['departamento']);
            }
            $encontradas[] = $ciudad;
        }

        return $encontradas;
    }

    /**
     * @param  list<object>  $filas
     * @return list<int>
     */
    private function uniqueIds(array $filas, string $campo): array
    {
        return array_values(array_unique(array_map(fn (object $fila): int => (int) $fila->{$campo}, $filas)));
    }

    /**
     * @param  list<int>  $ids
     */
    private function syncPivot(string $table, string $fk, int $organizacionId, array $ids, mixed $now): void
    {
        DB::table($table)->where('organizacion_id', $organizacionId)->whereNotIn($fk, $ids ?: [0])->delete();
        foreach ($ids as $id) {
            DB::table($table)->updateOrInsert(
                ['organizacion_id' => $organizacionId, $fk => $id],
                ['created_at' => $now, 'updated_at' => $now],
            );
        }
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
