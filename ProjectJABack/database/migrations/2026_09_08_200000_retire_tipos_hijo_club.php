<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $now = now();
        $retiredIds = $this->retiredTipoIds();
        if ($retiredIds === []) {
            return;
        }

        $orgs = DB::table('organizacion')
            ->whereIn('tipo_organizacion_id', $retiredIds)
            ->get(['id', 'nombre', 'organizacion_padre_id', 'tipo_organizacion_id']);

        $tipoNombres = DB::table('tipo_organizacion')
            ->whereIn('id', $retiredIds)
            ->pluck('nombre', 'id');

        foreach ($orgs as $org) {
            $padreId = $org->organizacion_padre_id ? (int) $org->organizacion_padre_id : null;
            if ($padreId) {
                $padreTipo = (int) (DB::table('organizacion')
                    ->where('id', $padreId)
                    ->value('tipo_organizacion_id') ?? 0);
                if ($padreTipo === 5) {
                    $padreId = DB::table('organizacion')
                        ->where('id', $org->organizacion_padre_id)
                        ->value('organizacion_padre_id');
                    $padreId = $padreId ? (int) $padreId : null;
                }
            }

            DB::table('organizacion')->where('id', $org->id)->update([
                'tipo_organizacion_id' => 5,
                'organizacion_padre_id' => $padreId,
                'fecha_actualizacion' => $now,
            ]);

            $ministry = $this->ministryFromTipoNombre((string) ($tipoNombres[$org->tipo_organizacion_id] ?? ''));
            if ($ministry === null || ! Schema::hasTable('clubes')) {
                continue;
            }

            $club = DB::table('clubes')->where('organizacion_id', $org->id)->first();
            if (! $club) {
                DB::table('clubes')->insert([
                    'organizacion_id' => $org->id,
                    'nombre' => $org->nombre,
                    'tipos' => json_encode([$ministry]),
                    'is_active' => true,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);

                continue;
            }

            $tipos = is_string($club->tipos) ? json_decode($club->tipos, true) : $club->tipos;
            if (! is_array($tipos) || $tipos === []) {
                DB::table('clubes')->where('id', $club->id)->update([
                    'tipos' => json_encode([$ministry]),
                    'updated_at' => $now,
                ]);
            }
        }

        DB::table('tipo_organizacion')
            ->whereIn('id', $retiredIds)
            ->update([
                'estado' => false,
                'descripcion' => 'Audiencia de eventos (no es tipo de organización)',
                'updated_at' => $now,
            ]);
    }

    public function down(): void
    {
        $now = now();
        DB::table('tipo_organizacion')
            ->whereIn('id', [6, 7, 8])
            ->orWhereIn('nombre', ['Aventureros', 'Conquistadores', 'Guías Mayores', 'Guias Mayores'])
            ->update([
                'estado' => true,
                'tipo_organizacion_padre_id' => 5,
                'descripcion' => 'Hijo de Club',
                'updated_at' => $now,
            ]);
    }

    /**
     * @return list<int>
     */
    private function retiredTipoIds(): array
    {
        return DB::table('tipo_organizacion')
            ->where(function ($query) {
                $query->whereIn('id', [6, 7, 8])
                    ->orWhereIn('nombre', ['Aventureros', 'Conquistadores', 'Guías Mayores', 'Guias Mayores']);
            })
            ->pluck('id')
            ->map(fn ($id) => (int) $id)
            ->unique()
            ->values()
            ->all();
    }

    private function ministryFromTipoNombre(string $nombre): ?string
    {
        $normalizado = mb_strtolower($nombre);
        if (str_contains($normalizado, 'aventurer')) {
            return 'aventureros';
        }
        if (str_contains($normalizado, 'conquistador')) {
            return 'conquistadores';
        }
        if (str_contains($normalizado, 'guia') || str_contains($normalizado, 'guía')) {
            return 'guias_mayores';
        }

        return null;
    }
};
