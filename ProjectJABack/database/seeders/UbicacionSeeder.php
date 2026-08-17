<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use stdClass;

class UbicacionSeeder extends Seeder
{
    public function run(): void
    {
        $now = now();
        /** @var list<array{codigo: string, nombre: string, municipios: list<array{codigo: string, nombre: string}>}> $catalogo */
        $catalogo = require database_path('data/dian_divipola.php');

        DB::table('pais')->updateOrInsert(
            ['nombre' => 'Colombia'],
            [
                'nombre' => 'Colombia',
                'codigo' => 'CO',
                'created_at' => $now,
                'updated_at' => $now,
            ],
        );

        $pais = DB::table('pais')->where('nombre', 'Colombia')->first();
        if (! $pais) {
            return;
        }

        $this->reubicarBogotaDesdeCundinamarca($pais->id, $now);

        foreach ($catalogo as $departamentoData) {
            $departamento = $this->upsertDepartamento($pais->id, $departamentoData, $now);
            if (! $departamento) {
                continue;
            }

            foreach ($departamentoData['municipios'] as $municipioData) {
                $this->upsertMunicipio((int) $departamento->id, $municipioData, $now);
            }
        }
    }

    /**
     * @param  array{codigo: string, nombre: string}  $departamentoData
     */
    private function upsertDepartamento(int $paisId, array $departamentoData, mixed $now): ?stdClass
    {
        $existente = DB::table('departamento')
            ->where('pais_id', $paisId)
            ->where(function ($query) use ($departamentoData) {
                $query->where('codigo', $departamentoData['codigo'])
                    ->orWhere('nombre', $departamentoData['nombre']);
            })
            ->first();

        $payload = [
            'pais_id' => $paisId,
            'codigo' => $departamentoData['codigo'],
            'nombre' => $departamentoData['nombre'],
            'updated_at' => $now,
        ];

        if ($existente) {
            DB::table('departamento')->where('id', $existente->id)->update($payload);

            return DB::table('departamento')->where('id', $existente->id)->first();
        }

        $payload['created_at'] = $now;
        $id = DB::table('departamento')->insertGetId($payload);

        return DB::table('departamento')->where('id', $id)->first();
    }

    /**
     * @param  array{codigo: string, nombre: string}  $municipioData
     */
    private function upsertMunicipio(int $departamentoId, array $municipioData, mixed $now): void
    {
        $existente = DB::table('ciudad')
            ->where(function ($query) use ($departamentoId, $municipioData) {
                $query->where('codigo', $municipioData['codigo'])
                    ->orWhere(function ($sameDepartment) use ($departamentoId, $municipioData) {
                        $sameDepartment->where('departamento_id', $departamentoId)
                            ->where('nombre', $municipioData['nombre']);
                    });
            })
            ->first();

        $payload = [
            'departamento_id' => $departamentoId,
            'codigo' => $municipioData['codigo'],
            'nombre' => $municipioData['nombre'],
            'updated_at' => $now,
        ];

        if ($existente) {
            DB::table('ciudad')->where('id', $existente->id)->update($payload);

            return;
        }

        $payload['created_at'] = $now;
        DB::table('ciudad')->insert($payload);
    }

    private function reubicarBogotaDesdeCundinamarca(int $paisId, mixed $now): void
    {
        $cundinamarca = DB::table('departamento')
            ->where('pais_id', $paisId)
            ->where(function ($query) {
                $query->where('codigo', '25')
                    ->orWhere('nombre', 'Cundinamarca');
            })
            ->first();

        if (! $cundinamarca) {
            return;
        }

        $bogota = DB::table('ciudad')
            ->where('departamento_id', $cundinamarca->id)
            ->where('nombre', 'like', 'Bogotá%')
            ->first();

        if (! $bogota) {
            return;
        }

        $distrito = $this->upsertDepartamento($paisId, [
            'codigo' => '11',
            'nombre' => 'Bogotá, D.C.',
        ], $now);

        if (! $distrito) {
            return;
        }

        DB::table('ciudad')->where('id', $bogota->id)->update([
            'departamento_id' => $distrito->id,
            'codigo' => '11001',
            'nombre' => 'Bogotá, D.C.',
            'updated_at' => $now,
        ]);
    }
}
