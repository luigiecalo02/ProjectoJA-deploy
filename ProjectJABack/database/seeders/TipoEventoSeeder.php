<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class TipoEventoSeeder extends Seeder
{
    public function run(): void
    {
        $now = now();
        $tipos = [
            [
                'nombre' => 'Eventos Bíblicos',
                'slug' => 'eventos-biblicos',
                'descripcion' => 'Estudios, concursos y actividades bíblicas',
                'color' => '#2563eb',
                'icono' => 'pi pi-book',
                'orden' => 1,
            ],
            [
                'nombre' => 'Eventos Deportivos',
                'slug' => 'eventos-deportivos',
                'descripcion' => 'Competencias y actividades deportivas',
                'color' => '#16a34a',
                'icono' => 'pi pi-bolt',
                'orden' => 2,
            ],
            [
                'nombre' => 'Eventos Precamporee',
                'slug' => 'eventos-precamporee',
                'descripcion' => 'Actividades preparatorias de camporee',
                'color' => '#ea580c',
                'icono' => 'pi pi-flag',
                'orden' => 3,
            ],
            [
                'nombre' => 'Camporee',
                'slug' => 'camporee',
                'descripcion' => 'Camporee y eventos mayores de campamento',
                'color' => '#7c3aed',
                'icono' => 'pi pi-map',
                'orden' => 4,
            ],
        ];

        foreach ($tipos as $tipo) {
            DB::table('tipo_evento')->updateOrInsert(
                ['slug' => $tipo['slug']],
                [
                    'nombre' => $tipo['nombre'],
                    'descripcion' => $tipo['descripcion'],
                    'color' => $tipo['color'],
                    'icono' => $tipo['icono'],
                    'orden' => $tipo['orden'],
                    'estado' => true,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]
            );
        }
    }
}
