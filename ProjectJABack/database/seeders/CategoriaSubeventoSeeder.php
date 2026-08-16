<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class CategoriaSubeventoSeeder extends Seeder
{
    public function run(): void
    {
        $now = now();
        $items = [
            [
                'nombre' => 'Especialidades',
                'slug' => 'especialidades',
                'color' => '#7c3aed',
                'icono' => 'pi pi-star',
                'orden' => 1,
            ],
            [
                'nombre' => 'Conocimiento',
                'slug' => 'conocimiento',
                'color' => '#2563eb',
                'icono' => 'pi pi-book',
                'orden' => 2,
            ],
            [
                'nombre' => 'Habilidades',
                'slug' => 'habilidades',
                'color' => '#16a34a',
                'icono' => 'pi pi-wrench',
                'orden' => 3,
            ],
            [
                'nombre' => 'Desafíos',
                'slug' => 'desafios',
                'color' => '#dc2626',
                'icono' => 'pi pi-bolt',
                'orden' => 4,
            ],
        ];

        foreach ($items as $item) {
            DB::table('categoria_subevento')->updateOrInsert(
                ['slug' => $item['slug']],
                [
                    'nombre' => $item['nombre'],
                    'color' => $item['color'],
                    'icono' => $item['icono'],
                    'orden' => $item['orden'],
                    'estado' => true,
                    'maneja_puntos' => true,
                    'maneja_fecha_inicio' => false,
                    'maneja_fecha_fin' => false,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]
            );
        }
    }
}
