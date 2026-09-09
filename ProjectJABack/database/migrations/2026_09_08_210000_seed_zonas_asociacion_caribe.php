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
            throw new \RuntimeException(
                'No existe la Asociación "'.self::ASOCIACION.'". Créala antes de correr esta migración.'
            );
        }
        if (! DB::table('tipo_organizacion')->where('id', self::TIPO_ZONA)->exists()) {
            throw new \RuntimeException('Falta el tipo de organización Zona (id 9).');
        }

        $now = now();

        foreach ($this->zonas() as $zonaData) {
            $ciudades = $this->resolverCiudades($zonaData['municipios']);
            $departamentoIds = $this->uniqueIds($ciudades, 'departamento_id');
            $ciudadIds = $this->uniqueIds($ciudades, 'id');

            foreach ($departamentoIds as $departamentoId) {
                $this->touchPivot('organizacion_departamento', 'departamento_id', (int) $asociacion->id, $departamentoId, $now);
            }

            $primera = $ciudades[0] ?? null;
            $zonaId = $this->upsertOrganizacion([
                'padre_id' => (int) $asociacion->id,
                'tipo_id' => self::TIPO_ZONA,
                'nombre' => $zonaData['nombre'],
                'pais_id' => $asociacion->pais_id,
                'departamento_id' => $primera?->departamento_id,
                'ciudad_id' => $primera?->id,
                'direccion' => null,
                'codigo_padre' => $asociacion->codigo,
                'prefijo' => 'ZON',
            ], $now);

            $this->syncPivot('organizacion_departamento', 'departamento_id', $zonaId, $departamentoIds, $now);
            $this->syncPivot('organizacion_ciudad', 'ciudad_id', $zonaId, $ciudadIds, $now);

            $zona = DB::table('organizacion')->where('id', $zonaId)->first();

            foreach ($zonaData['distritos'] as $distritoData) {
                $distritoId = $this->upsertOrganizacion([
                    'padre_id' => $zonaId,
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
        $asociacion = $this->asociacion();
        if (! $asociacion) {
            return;
        }

        $zonaIds = DB::table('organizacion')
            ->where('organizacion_padre_id', $asociacion->id)
            ->where('tipo_organizacion_id', self::TIPO_ZONA)
            ->whereIn('nombre', array_column($this->zonas(), 'nombre'))
            ->pluck('id');

        DB::table('organizacion_departamento')->whereIn('organizacion_id', $zonaIds)->delete();
        DB::table('organizacion_ciudad')->whereIn('organizacion_id', $zonaIds)->delete();
        DB::table('organizacion')
            ->whereIn('id', $zonaIds)
            ->whereNotExists(function ($query) {
                $query->select(DB::raw(1))
                    ->from('organizacion as hijas')
                    ->whereColumn('hijas.organizacion_padre_id', 'organizacion.id');
            })
            ->delete();
    }

    private function asociacion(): ?object
    {
        return DB::table('organizacion')
            ->where('tipo_organizacion_id', self::TIPO_ASOCIACION)
            ->where('nombre', self::ASOCIACION)
            ->first();
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

        DB::table('clubes')->insert(array_merge($payload, [
            'created_at' => $now,
        ]));
    }

    /**
     * @return list<array{
     *     nombre: string,
     *     municipios: list<array{departamento: string, nombre: string}>,
     *     distritos: list<array{nombre: string, clubes: list<string>}>
     * }>
     */
    private function zonas(): array
    {
        return [
            [
                'nombre' => 'Zona Cartagena',
                'municipios' => [
                    ['departamento' => 'Bolívar', 'nombre' => 'Cartagena'],
                    ['departamento' => 'Bolívar', 'nombre' => 'Turbaco'],
                    ['departamento' => 'Bolívar', 'nombre' => 'Turbaná'],
                    ['departamento' => 'Bolívar', 'nombre' => 'Arjona'],
                    ['departamento' => 'Bolívar', 'nombre' => 'Santa Rosa'],
                    ['departamento' => 'Bolívar', 'nombre' => 'Clemencia'],
                    ['departamento' => 'Bolívar', 'nombre' => 'Santa Catalina'],
                    ['departamento' => 'Bolívar', 'nombre' => 'Villanueva'],
                ],
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
                'municipios' => [
                    ['departamento' => 'Antioquia', 'nombre' => 'Nechí'],
                    ['departamento' => 'Bolívar', 'nombre' => 'Achí'],
                    ['departamento' => 'Bolívar', 'nombre' => 'Magangué'],
                    ['departamento' => 'Bolívar', 'nombre' => 'San Jacinto del Cauca'],
                    ['departamento' => 'Córdoba', 'nombre' => 'Ayapel'],
                    ['departamento' => 'Sucre', 'nombre' => 'Caimito'],
                    ['departamento' => 'Sucre', 'nombre' => 'Guaranda'],
                    ['departamento' => 'Sucre', 'nombre' => 'Majagual'],
                    ['departamento' => 'Sucre', 'nombre' => 'San Benito Abad'],
                    ['departamento' => 'Sucre', 'nombre' => 'San Marcos'],
                    ['departamento' => 'Sucre', 'nombre' => 'Sucre'],
                ],
                'distritos' => [
                    ['nombre' => 'ACHI', 'clubes' => ['BARKLAY']],
                    ['nombre' => 'MAJAGUAL', 'clubes' => ['FUSION MAJAGUAL']],
                    ['nombre' => 'GUARANDA', 'clubes' => ['JADÁ', 'MALAKH']],
                ],
            ],
            [
                'nombre' => 'Zona de la Sabana',
                'municipios' => [
                    ['departamento' => 'Sucre', 'nombre' => 'Sincelejo'],
                ],
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
                'municipios' => [
                    ['departamento' => 'Córdoba', 'nombre' => 'Montería'],
                ],
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
                'municipios' => [
                    ['departamento' => 'Bolívar', 'nombre' => 'Córdoba'],
                    ['departamento' => 'Bolívar', 'nombre' => 'El Carmen de Bolívar'],
                    ['departamento' => 'Bolívar', 'nombre' => 'El Guamo'],
                    ['departamento' => 'Bolívar', 'nombre' => 'María la Baja'],
                    ['departamento' => 'Bolívar', 'nombre' => 'San Jacinto'],
                    ['departamento' => 'Bolívar', 'nombre' => 'San Juan Nepomuceno'],
                    ['departamento' => 'Bolívar', 'nombre' => 'Zambrano'],
                    ['departamento' => 'Sucre', 'nombre' => 'Chalán'],
                    ['departamento' => 'Sucre', 'nombre' => 'Coloso'],
                    ['departamento' => 'Sucre', 'nombre' => 'Los Palmitos'],
                    ['departamento' => 'Sucre', 'nombre' => 'Morroa'],
                    ['departamento' => 'Sucre', 'nombre' => 'Ovejas'],
                    ['departamento' => 'Sucre', 'nombre' => 'Palmito'],
                    ['departamento' => 'Sucre', 'nombre' => 'San Onofre'],
                    ['departamento' => 'Sucre', 'nombre' => 'Tolú Viejo'],
                ],
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
                'municipios' => [
                    ['departamento' => 'Córdoba', 'nombre' => 'Tierralta'],
                    ['departamento' => 'Córdoba', 'nombre' => 'Valencia'],
                ],
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
                throw new \RuntimeException(
                    'No existe el municipio "'.$municipio['nombre'].'" en '.$municipio['departamento']
                );
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
        return array_values(array_unique(array_map(
            fn (object $fila): int => (int) $fila->{$campo},
            $filas,
        )));
    }

    private function touchPivot(string $table, string $fk, int $organizacionId, int $id, mixed $now): void
    {
        DB::table($table)->updateOrInsert(
            ['organizacion_id' => $organizacionId, $fk => $id],
            ['created_at' => $now, 'updated_at' => $now],
        );
    }

    /**
     * @param  list<int>  $ids
     */
    private function syncPivot(string $table, string $fk, int $organizacionId, array $ids, mixed $now): void
    {
        DB::table($table)->where('organizacion_id', $organizacionId)->whereNotIn($fk, $ids ?: [0])->delete();
        foreach ($ids as $id) {
            $this->touchPivot($table, $fk, $organizacionId, $id, $now);
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
