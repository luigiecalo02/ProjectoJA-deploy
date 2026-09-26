<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const SLUGS = [
        'eventos-biblicos',
        'eventos-deportivos',
        'eventos-precamporee',
    ];

    public function up(): void
    {
        if (! Schema::hasTable('tipo_evento')) {
            return;
        }

        DB::table('tipo_evento')->whereIn('slug', self::SLUGS)->delete();
    }

    public function down(): void
    {
        if (! Schema::hasTable('tipo_evento')) {
            return;
        }

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
        ];

        foreach ($tipos as $tipo) {
            DB::table('tipo_evento')->updateOrInsert(
                ['slug' => $tipo['slug']],
                [
                    ...$tipo,
                    'estado' => true,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]
            );
        }
    }
};
