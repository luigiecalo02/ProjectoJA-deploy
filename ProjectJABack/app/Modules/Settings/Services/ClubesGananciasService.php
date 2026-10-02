<?php

namespace App\Modules\Settings\Services;

use App\Models\User;
use App\Modules\Settings\Models\AppSetting;
use App\Modules\Settings\Models\ClubesGananciaDistribucion;
use App\Modules\Settings\Models\ClubesGananciaEclesiastica;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\Response;

final class ClubesGananciasService
{
    /**
     * @return list<array<string, mixed>>
     */
    public function listDistribuciones(User $actor): array
    {
        $orgId = $this->assertCanView($actor);

        return ClubesGananciaDistribucion::query()
            ->where('organizacion_id', $orgId)
            ->orderByDesc('es_predeterminada')
            ->orderBy('nombre')
            ->get()
            ->map(fn (ClubesGananciaDistribucion $row) => $this->distribucionPayload($row))
            ->values()
            ->all();
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public function createDistribucion(User $actor, array $data): array
    {
        $orgId = $this->assertCanWrite($actor);
        $percents = $this->normalizedPercents($data, ['club', 'miembros', 'extras']);
        $this->assertSum100($percents, 'club');

        $row = DB::transaction(function () use ($orgId, $data, $percents) {
            $default = (bool) ($data['es_predeterminada'] ?? false);
            if ($default) {
                $this->clearDefaultDistribuciones($orgId);
            }

            return ClubesGananciaDistribucion::query()->create([
                'organizacion_id' => $orgId,
                'nombre' => trim((string) $data['nombre']),
                'club' => $percents['club'],
                'miembros' => $percents['miembros'],
                'extras' => $percents['extras'],
                'es_predeterminada' => $default,
            ]);
        });

        return $this->distribucionPayload($row);
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public function updateDistribucion(User $actor, int $id, array $data): array
    {
        $orgId = $this->assertCanWrite($actor);
        $row = $this->ownedDistribucion($id, $orgId);
        $percents = $this->normalizedPercents($data, ['club', 'miembros', 'extras'], $row);
        $this->assertSum100($percents, 'club');

        $row = DB::transaction(function () use ($orgId, $row, $data, $percents) {
            $default = array_key_exists('es_predeterminada', $data)
                ? (bool) $data['es_predeterminada']
                : (bool) $row->es_predeterminada;
            if ($default) {
                $this->clearDefaultDistribuciones($orgId, (int) $row->id);
            }

            $row->update([
                'nombre' => array_key_exists('nombre', $data) ? trim((string) $data['nombre']) : $row->nombre,
                'club' => $percents['club'],
                'miembros' => $percents['miembros'],
                'extras' => $percents['extras'],
                'es_predeterminada' => $default,
            ]);

            return $row->fresh() ?? $row;
        });

        return $this->distribucionPayload($row);
    }

    public function deleteDistribucion(User $actor, int $id): void
    {
        $orgId = $this->assertCanWrite($actor);
        $row = $this->ownedDistribucion($id, $orgId);

        if ($row->eclesiasticas()->exists()) {
            throw ValidationException::withMessages([
                'distribucion' => ['No se puede borrar: una configuración eclesiástica la está usando.'],
            ]);
        }

        $row->delete();
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function listEclesiasticas(User $actor): array
    {
        $orgId = $this->assertCanView($actor);

        return ClubesGananciaEclesiastica::query()
            ->with('distribucion')
            ->where('organizacion_id', $orgId)
            ->orderByDesc('es_predeterminada')
            ->orderBy('nombre')
            ->get()
            ->map(fn (ClubesGananciaEclesiastica $row) => $this->eclesiasticaPayload($row))
            ->values()
            ->all();
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public function createEclesiastica(User $actor, array $data): array
    {
        $orgId = $this->assertCanWrite($actor);
        $percents = $this->normalizedPercents($data, ['diezmo', 'ofrenda']);
        $this->assertChurchCap($percents);
        $distribucion = $this->ownedDistribucion((int) $data['distribucion_id'], $orgId);

        $row = DB::transaction(function () use ($orgId, $data, $percents, $distribucion) {
            $default = (bool) ($data['es_predeterminada'] ?? false);
            if ($default) {
                $this->clearDefaultEclesiasticas($orgId);
            }

            return ClubesGananciaEclesiastica::query()->create([
                'organizacion_id' => $orgId,
                'distribucion_id' => $distribucion->id,
                'nombre' => trim((string) $data['nombre']),
                'diezmo' => $percents['diezmo'],
                'ofrenda' => $percents['ofrenda'],
                'es_predeterminada' => $default,
            ]);
        });

        return $this->eclesiasticaPayload($row->load('distribucion'));
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public function updateEclesiastica(User $actor, int $id, array $data): array
    {
        $orgId = $this->assertCanWrite($actor);
        $row = $this->ownedEclesiastica($id, $orgId);
        $percents = $this->normalizedPercents($data, ['diezmo', 'ofrenda'], $row);
        $this->assertChurchCap($percents);
        $distribucionId = array_key_exists('distribucion_id', $data)
            ? (int) $data['distribucion_id']
            : (int) $row->distribucion_id;
        $distribucion = $this->ownedDistribucion($distribucionId, $orgId);

        $row = DB::transaction(function () use ($orgId, $row, $data, $percents, $distribucion) {
            $default = array_key_exists('es_predeterminada', $data)
                ? (bool) $data['es_predeterminada']
                : (bool) $row->es_predeterminada;
            if ($default) {
                $this->clearDefaultEclesiasticas($orgId, (int) $row->id);
            }

            $row->update([
                'nombre' => array_key_exists('nombre', $data) ? trim((string) $data['nombre']) : $row->nombre,
                'diezmo' => $percents['diezmo'],
                'ofrenda' => $percents['ofrenda'],
                'distribucion_id' => $distribucion->id,
                'es_predeterminada' => $default,
            ]);

            return $row->fresh(['distribucion']) ?? $row->load('distribucion');
        });

        return $this->eclesiasticaPayload($row);
    }

    public function deleteEclesiastica(User $actor, int $id): void
    {
        $orgId = $this->assertCanWrite($actor);
        $this->ownedEclesiastica($id, $orgId)->delete();
    }

    private function assertCanView(User $actor): int
    {
        $orgId = AppSetting::resolveOrganizacionId();
        abort_unless($orgId, Response::HTTP_FORBIDDEN, 'Debes tener una organización activa.');
        abort_unless(
            $this->actorIsDirector($actor)
            || $actor->hasPermission('settings.view')
            || $actor->hasPermission('settings.update'),
            Response::HTTP_FORBIDDEN,
            'No puedes ver esta configuración.',
        );

        return $orgId;
    }

    private function assertCanWrite(User $actor): int
    {
        $orgId = $this->assertCanView($actor);
        abort_unless(
            $this->actorIsDirector($actor) || $actor->hasPermission('settings.update'),
            Response::HTTP_FORBIDDEN,
            'Solo el director del club puede actualizar esta configuración.',
        );

        return $orgId;
    }

    private function actorIsDirector(User $actor): bool
    {
        return in_array('director', $actor->roleNames(), true);
    }

    private function ownedDistribucion(int $id, int $orgId): ClubesGananciaDistribucion
    {
        $row = ClubesGananciaDistribucion::query()->find($id);
        abort_unless($row && (int) $row->organizacion_id === $orgId, Response::HTTP_NOT_FOUND);

        return $row;
    }

    private function ownedEclesiastica(int $id, int $orgId): ClubesGananciaEclesiastica
    {
        $row = ClubesGananciaEclesiastica::query()->find($id);
        abort_unless($row && (int) $row->organizacion_id === $orgId, Response::HTTP_NOT_FOUND);

        return $row;
    }

    /**
     * @param  array<string, mixed>  $data
     * @param  list<string>  $keys
     * @return array<string, float>
     */
    private function normalizedPercents(array $data, array $keys, ?object $current = null): array
    {
        $out = [];
        foreach ($keys as $key) {
            $raw = array_key_exists($key, $data) ? $data[$key] : ($current?->{$key} ?? 0);
            $value = round((float) $raw, 2);
            if ($value < 0 || $value > 100) {
                throw ValidationException::withMessages([
                    $key => ['El porcentaje debe estar entre 0 y 100.'],
                ]);
            }
            $out[$key] = $value;
        }

        return $out;
    }

    /**
     * @param  array<string, float>  $percents
     */
    private function assertSum100(array $percents, string $field): void
    {
        $sum = round(array_sum($percents), 2);
        if (abs($sum - 100.0) > 0.009) {
            throw ValidationException::withMessages([
                $field => ['Club, miembros y extras deben sumar 100%.'],
            ]);
        }
    }

    /**
     * @param  array{diezmo: float, ofrenda: float}  $percents
     */
    private function assertChurchCap(array $percents): void
    {
        $sum = round($percents['diezmo'] + $percents['ofrenda'], 2);
        if ($sum > 100) {
            throw ValidationException::withMessages([
                'diezmo' => ['Diezmo y ofrenda no pueden sumar más de 100%.'],
            ]);
        }
    }

    private function clearDefaultDistribuciones(int $orgId, ?int $keepId = null): void
    {
        ClubesGananciaDistribucion::query()
            ->where('organizacion_id', $orgId)
            ->when($keepId, fn ($query) => $query->where('id', '!=', $keepId))
            ->update(['es_predeterminada' => false]);
    }

    private function clearDefaultEclesiasticas(int $orgId, ?int $keepId = null): void
    {
        ClubesGananciaEclesiastica::query()
            ->where('organizacion_id', $orgId)
            ->when($keepId, fn ($query) => $query->where('id', '!=', $keepId))
            ->update(['es_predeterminada' => false]);
    }

    /**
     * @return array<string, mixed>
     */
    private function distribucionPayload(ClubesGananciaDistribucion $row): array
    {
        return [
            'id' => (int) $row->id,
            'organizacion_id' => (int) $row->organizacion_id,
            'nombre' => $row->nombre,
            'club' => (float) $row->club,
            'miembros' => (float) $row->miembros,
            'extras' => (float) $row->extras,
            'es_predeterminada' => (bool) $row->es_predeterminada,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function eclesiasticaPayload(ClubesGananciaEclesiastica $row): array
    {
        $diezmo = (float) $row->diezmo;
        $ofrenda = (float) $row->ofrenda;

        return [
            'id' => (int) $row->id,
            'organizacion_id' => (int) $row->organizacion_id,
            'nombre' => $row->nombre,
            'diezmo' => $diezmo,
            'ofrenda' => $ofrenda,
            'resto' => round(100 - $diezmo - $ofrenda, 2),
            'distribucion_id' => (int) $row->distribucion_id,
            'distribucion' => $row->distribucion ? $this->distribucionPayload($row->distribucion) : null,
            'es_predeterminada' => (bool) $row->es_predeterminada,
        ];
    }
}
