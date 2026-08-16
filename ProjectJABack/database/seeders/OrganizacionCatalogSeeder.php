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
        // Unión → Asociación → Distrito → Iglesia → Club → (Conquistadores | Aventureros | Guías Mayores)
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
                'id' => 3,
                'tipo_organizacion_padre_id' => 2,
                'nombre' => 'Distrito',
                'descripcion' => 'Hijo de Asociación',
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

        // Hijos de Club: upsert por nombre para respetar IDs ya existentes.
        $hijosClub = [
            [
                'nombre' => 'Conquistadores',
                'descripcion' => 'Hijo de Club',
            ],
            [
                'nombre' => 'Aventureros',
                'descripcion' => 'Hijo de Club',
            ],
            [
                'nombre' => 'Guías Mayores',
                'nombres_alternativos' => ['Guias Mayores', 'Guías Mayores'],
                'descripcion' => 'Hijo de Club',
            ],
        ];

        foreach ($hijosClub as $hijo) {
            $nombres = $hijo['nombres_alternativos'] ?? [$hijo['nombre']];
            $existente = DB::table('tipo_organizacion')->whereIn('nombre', $nombres)->first();

            if ($existente) {
                DB::table('tipo_organizacion')->where('id', $existente->id)->update([
                    'tipo_organizacion_padre_id' => 5,
                    'nombre' => $hijo['nombre'],
                    'descripcion' => $hijo['descripcion'],
                    'estado' => true,
                    'updated_at' => $now,
                ]);
            } else {
                DB::table('tipo_organizacion')->insert([
                    'tipo_organizacion_padre_id' => 5,
                    'nombre' => $hijo['nombre'],
                    'descripcion' => $hijo['descripcion'],
                    'estado' => true,
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
