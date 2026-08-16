<?php

namespace App\Modules\Organizations\Services;

use App\Modules\Organizations\Models\Ciudad;
use App\Modules\Organizations\Models\Departamento;
use App\Modules\Organizations\Models\Pais;
use Illuminate\Validation\ValidationException;

final class UbicacionService
{
    /**
     * @return list<Pais>
     */
    public function paises(): array
    {
        return Pais::query()->orderBy('nombre')->get()->all();
    }

    /**
     * @return list<Departamento>
     */
    public function departamentos(?int $paisId = null): array
    {
        $query = Departamento::query()->with('pais:id,nombre')->orderBy('nombre');

        if ($paisId) {
            $query->where('pais_id', $paisId);
        }

        return $query->get()->all();
    }

    /**
     * @return list<Ciudad>
     */
    public function ciudades(?int $departamentoId = null): array
    {
        $query = Ciudad::query()->with('departamento:id,nombre,pais_id')->orderBy('nombre');

        if ($departamentoId) {
            $query->where('departamento_id', $departamentoId);
        }

        return $query->get()->all();
    }

    public function resolvePais(?int $paisId, ?string $nombre): ?Pais
    {
        if ($paisId) {
            $pais = Pais::query()->find($paisId);
            if (! $pais) {
                throw ValidationException::withMessages([
                    'pais_id' => ['El país seleccionado no existe.'],
                ]);
            }

            return $pais;
        }

        $nombre = trim((string) $nombre);
        if ($nombre === '') {
            return null;
        }

        return Pais::query()->firstOrCreate(
            ['nombre' => $nombre],
            ['nombre' => $nombre],
        );
    }

    public function resolveDepartamento(int $paisId, ?int $departamentoId, ?string $nombre): ?Departamento
    {
        if ($departamentoId) {
            $departamento = Departamento::query()->find($departamentoId);
            if (! $departamento) {
                throw ValidationException::withMessages([
                    'departamento_id' => ['El departamento seleccionado no existe.'],
                ]);
            }
            if ((int) $departamento->pais_id !== $paisId) {
                throw ValidationException::withMessages([
                    'departamento_id' => ['El departamento no pertenece al país indicado.'],
                ]);
            }

            return $departamento;
        }

        $nombre = trim((string) $nombre);
        if ($nombre === '') {
            return null;
        }

        return Departamento::query()->firstOrCreate(
            ['pais_id' => $paisId, 'nombre' => $nombre],
            ['pais_id' => $paisId, 'nombre' => $nombre],
        );
    }

    public function resolveCiudad(int $departamentoId, ?int $ciudadId, ?string $nombre): ?Ciudad
    {
        if ($ciudadId) {
            $ciudad = Ciudad::query()->find($ciudadId);
            if (! $ciudad) {
                throw ValidationException::withMessages([
                    'ciudad_id' => ['La ciudad seleccionada no existe.'],
                ]);
            }
            if ((int) $ciudad->departamento_id !== $departamentoId) {
                throw ValidationException::withMessages([
                    'ciudad_id' => ['La ciudad no pertenece al departamento indicado.'],
                ]);
            }

            return $ciudad;
        }

        $nombre = trim((string) $nombre);
        if ($nombre === '') {
            return null;
        }

        return Ciudad::query()->firstOrCreate(
            ['departamento_id' => $departamentoId, 'nombre' => $nombre],
            ['departamento_id' => $departamentoId, 'nombre' => $nombre],
        );
    }
}
