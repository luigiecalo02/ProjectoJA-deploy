<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class OrganizacionCatalogSeeder extends Seeder
{
    public function run(): void
    {
        $now = now();

        // Jerarquía de tipos:
        // Unión → Asociación → Zona → Distrito → Iglesia → Club
        $tipos = [
            [
                'id' => 1,
                'tipo_organizacion_padre_id' => null,
                'nombre' => 'Unión',
                'descripcion' => 'Nivel de Unión (sin padre)',
                'estado' => true,
            ],
            [
                'id' => 2,
                'tipo_organizacion_padre_id' => 1,
                'nombre' => 'Asociación',
                'descripcion' => 'Hijo de Unión',
                'estado' => true,
            ],
            [
                'id' => 9,
                'tipo_organizacion_padre_id' => 2,
                'nombre' => 'Zona',
                'descripcion' => 'Hijo de Asociación; agrupa municipios y es padre del Distrito',
                'estado' => true,
            ],
            [
                'id' => 3,
                'tipo_organizacion_padre_id' => 9,
                'nombre' => 'Distrito',
                'descripcion' => 'Hijo de Zona',
                'estado' => true,
            ],
            [
                'id' => 4,
                'tipo_organizacion_padre_id' => 3,
                'nombre' => 'Iglesia',
                'descripcion' => 'Hijo de Distrito',
                'estado' => true,
            ],
            [
                'id' => 5,
                'tipo_organizacion_padre_id' => 4,
                'nombre' => 'Club',
                'descripcion' => 'Hijo de Iglesia',
                'estado' => true,
            ],
        ];

        foreach ($tipos as $tipo) {
            DB::table('tipo_organizacion')->updateOrInsert(
                ['id' => $tipo['id']],
                [
                    'tipo_organizacion_padre_id' => $tipo['tipo_organizacion_padre_id'],
                    'nombre' => $tipo['nombre'],
                    'descripcion' => $tipo['descripcion'],
                    'estado' => $tipo['estado'],
                    'created_at' => $now,
                    'updated_at' => $now,
                ]
            );
        }

        // Filas inactivas: no son tipos de organización. Se conservan para la audiencia de eventos.
        $audienciaEvento = [
            [
                'nombre' => 'Conquistadores',
                'descripcion' => 'Audiencia de eventos (no es tipo de organización)',
            ],
            [
                'nombre' => 'Aventureros',
                'descripcion' => 'Audiencia de eventos (no es tipo de organización)',
            ],
            [
                'nombre' => 'Guías Mayores',
                'nombres_alternativos' => ['Guias Mayores', 'Guías Mayores'],
                'descripcion' => 'Audiencia de eventos (no es tipo de organización)',
            ],
        ];

        foreach ($audienciaEvento as $tipo) {
            $nombres = $tipo['nombres_alternativos'] ?? [$tipo['nombre']];
            $existente = DB::table('tipo_organizacion')->whereIn('nombre', $nombres)->first();

            if ($existente) {
                DB::table('tipo_organizacion')->where('id', $existente->id)->update([
                    'nombre' => $tipo['nombre'],
                    'descripcion' => $tipo['descripcion'],
                    'estado' => false,
                    'updated_at' => $now,
                ]);
            } else {
                DB::table('tipo_organizacion')->insert([
                    'tipo_organizacion_padre_id' => 5,
                    'nombre' => $tipo['nombre'],
                    'descripcion' => $tipo['descripcion'],
                    'estado' => false,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
            }
        }

        $cargos = [
            ['nombre' => 'Director', 'descripcion' => 'Lidera la organización o club'],
            ['nombre' => 'Subdirector', 'descripcion' => 'Apoya la dirección y asume en su ausencia'],
            ['nombre' => 'Secretario', 'descripcion' => 'Gestiona actas, registros y documentación'],
            ['nombre' => 'Tesorero', 'descripcion' => 'Administra recursos e informes financieros'],
            ['nombre' => 'Consejero', 'descripcion' => 'Brinda orientación y acompañamiento'],
            ['nombre' => 'Instructor', 'descripcion' => 'Impulsa la formación y clases'],
            ['nombre' => 'Capellán', 'descripcion' => 'Acompañamiento espiritual'],
            ['nombre' => 'Conquistador', 'descripcion' => 'Integrante del club de conquistadores'],
        ];

        foreach ($cargos as $cargo) {
            DB::table('cargo')->updateOrInsert(
                ['nombre' => $cargo['nombre']],
                [
                    'descripcion' => $cargo['descripcion'],
                    'created_at' => $now,
                    'updated_at' => $now,
                ]
            );
        }
    }
}
