<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('tipo_evento')) {
            return;
        }

        $now = now();
        $tipos = [
            [
                'nombre' => 'Camporee',
                'slug' => 'camporee',
                'descripcion' => 'Camporee y eventos mayores de campamento',
                'color' => '#7c3aed',
                'icono' => 'pi pi-map',
                'orden' => 4,
            ],
            [
                'nombre' => 'Congreso',
                'slug' => 'congreso',
                'descripcion' => 'Congresos, asambleas y encuentros formativos',
                'color' => '#0f766e',
                'icono' => 'pi pi-users',
                'orden' => 5,
            ],
            [
                'nombre' => 'Actividad',
                'slug' => 'actividad',
                'descripcion' => 'Actividades regulares del club o la organización',
                'color' => '#0284c7',
                'icono' => 'pi pi-calendar',
                'orden' => 6,
            ],
            [
                'nombre' => 'Clase',
                'slug' => 'clase',
                'descripcion' => 'Clases, talleres y sesiones de instrucción',
                'color' => '#ca8a04',
                'icono' => 'pi pi-bookmark',
                'orden' => 7,
            ],
            [
                'nombre' => 'Investidura',
                'slug' => 'investidura',
                'descripcion' => 'Ceremonias de investidura y reconocimientos',
                'color' => '#be185d',
                'icono' => 'pi pi-star',
                'orden' => 8,
            ],
            [
                'nombre' => 'Campamento',
                'slug' => 'campamento',
                'descripcion' => 'Campamentos y salidas al campo',
                'color' => '#15803d',
                'icono' => 'pi pi-compass',
                'orden' => 9,
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
                    'updated_at' => $now,
                    'created_at' => $now,
                ]
            );
        }
    }

    public function down(): void
    {
        if (! Schema::hasTable('tipo_evento')) {
            return;
        }

        DB::table('tipo_evento')->whereIn('slug', [
            'congreso',
            'actividad',
            'clase',
            'investidura',
            'campamento',
        ])->delete();
    }
};
