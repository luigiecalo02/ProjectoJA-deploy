<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class UbicacionSeeder extends Seeder
{
    public function run(): void
    {
        $now = now();

        DB::table('pais')->updateOrInsert(
            ['nombre' => 'Colombia'],
            ['nombre' => 'Colombia', 'updated_at' => $now, 'created_at' => $now],
        );

        $pais = DB::table('pais')->where('nombre', 'Colombia')->first();
        if (! $pais) {
            return;
        }

        $departamentos = [
            'Antioquia' => ['Medellín', 'Envigado', 'Bello'],
            'Cundinamarca' => ['Bogotá', 'Soacha', 'Chía'],
            'Valle del Cauca' => ['Cali', 'Palmira', 'Buenaventura'],
            'Atlántico' => ['Barranquilla', 'Soledad'],
            'Santander' => ['Bucaramanga', 'Floridablanca'],
        ];

        foreach ($departamentos as $depNombre => $ciudades) {
            DB::table('departamento')->updateOrInsert(
                ['pais_id' => $pais->id, 'nombre' => $depNombre],
                [
                    'pais_id' => $pais->id,
                    'nombre' => $depNombre,
                    'created_at' => $now,
                    'updated_at' => $now,
                ],
            );

            $dep = DB::table('departamento')
                ->where('pais_id', $pais->id)
                ->where('nombre', $depNombre)
                ->first();

            if (! $dep) {
                continue;
            }

            foreach ($ciudades as $ciudadNombre) {
                DB::table('ciudad')->updateOrInsert(
                    ['departamento_id' => $dep->id, 'nombre' => $ciudadNombre],
                    [
                        'departamento_id' => $dep->id,
                        'nombre' => $ciudadNombre,
                        'created_at' => $now,
                        'updated_at' => $now,
                    ],
                );
            }
        }
    }
}
