<?php

namespace App\Modules\Terrains\Services;

use Illuminate\Validation\ValidationException;

final class GeometriaService
{
    /**
     * @param  array<string, mixed>|null  $geojson
     */
    public function validate(?array $geojson, bool $required = false): void
    {
        if ($geojson === null || $geojson === []) {
            if ($required) {
                throw ValidationException::withMessages([
                    'geometria' => ['La geometría es obligatoria.'],
                ]);
            }

            return;
        }

        $type = $geojson['type'] ?? null;
        if (! in_array($type, ['Polygon', 'MultiPolygon', 'Feature'], true)) {
            throw ValidationException::withMessages([
                'geometria' => ['La geometría debe ser Polygon, MultiPolygon o Feature GeoJSON.'],
            ]);
        }

        $geometry = $type === 'Feature' ? ($geojson['geometry'] ?? null) : $geojson;
        if (! is_array($geometry) || empty($geometry['coordinates'])) {
            throw ValidationException::withMessages([
                'geometria' => ['La geometría no contiene coordenadas válidas.'],
            ]);
        }
    }

    /**
     * @param  array<string, mixed>|null  $geojson
     * @return array{area: float|null, perimetro: float|null}
     */
    public function measure(?array $geojson): array
    {
        if ($geojson === null || $geojson === []) {
            return ['area' => null, 'perimetro' => null];
        }

        $rings = $this->extractOuterRings($geojson);
        if ($rings === []) {
            return ['area' => null, 'perimetro' => null];
        }

        $area = 0.0;
        $perimetro = 0.0;
        foreach ($rings as $ring) {
            $area += abs($this->ringAreaM2($ring));
            $perimetro += $this->ringPerimeterM($ring);
        }

        return [
            'area' => round($area, 2),
            'perimetro' => round($perimetro, 2),
        ];
    }

    public function capacidadFromArea(?float $areaM2, float $metrosPorPersona): ?int
    {
        if ($areaM2 === null || $areaM2 <= 0 || $metrosPorPersona <= 0) {
            return null;
        }

        return (int) max(1, floor($areaM2 / $metrosPorPersona));
    }

    /**
     * Contención: todos los vértices del polígono hijo deben estar dentro del padre.
     * Si el hijo tiene geometría y el padre no, se considera inválido.
     *
     * @param  array<string, mixed>|null  $child
     * @param  array<string, mixed>|null  $parent
     */
    public function isContained(?array $child, ?array $parent): bool
    {
        if ($child === null || $child === []) {
            return true;
        }

        $childRings = $this->extractOuterRings($child);
        if ($childRings === []) {
            return true;
        }

        if ($parent === null || $parent === []) {
            return false;
        }

        $parentRings = $this->extractOuterRings($parent);
        if ($parentRings === []) {
            return false;
        }

        foreach ($childRings as $childRing) {
            $points = $this->sampleRingPoints($childRing);
            foreach ($points as [$x, $y]) {
                if (! $this->pointInAnyRing($x, $y, $parentRings)) {
                    return false;
                }
            }
        }

        return true;
    }

    /**
     * Solape aproximado: algún punto muestreado de A cae en B, o de B en A.
     *
     * @param  array<string, mixed>|null  $a
     * @param  array<string, mixed>|null  $b
     */
    public function intersects(?array $a, ?array $b): bool
    {
        if ($a === null || $a === [] || $b === null || $b === []) {
            return false;
        }

        $ringsA = $this->extractOuterRings($a);
        $ringsB = $this->extractOuterRings($b);
        if ($ringsA === [] || $ringsB === []) {
            return false;
        }

        foreach ($ringsA as $ring) {
            foreach ($this->sampleRingPoints($ring) as [$x, $y]) {
                if ($this->pointInAnyRing($x, $y, $ringsB)) {
                    return true;
                }
            }
        }

        foreach ($ringsB as $ring) {
            foreach ($this->sampleRingPoints($ring) as [$x, $y]) {
                if ($this->pointInAnyRing($x, $y, $ringsA)) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * @deprecated Use isContained()
     *
     * @param  array<string, mixed>|null  $child
     * @param  array<string, mixed>|null  $parent
     */
    public function isApproximatelyContained(?array $child, ?array $parent): bool
    {
        return $this->isContained($child, $parent);
    }

    /**
     * @param  list<array{0: float, 1: float}>  $ring
     * @return list<array{0: float, 1: float}>
     */
    private function sampleRingPoints(array $ring): array
    {
        $points = [];
        $n = count($ring);
        if ($n === 0) {
            return [];
        }

        // Vértices (omitir cierre duplicado si existe)
        $limit = $n;
        if ($n > 1 && $ring[0][0] === $ring[$n - 1][0] && $ring[0][1] === $ring[$n - 1][1]) {
            $limit = $n - 1;
        }

        for ($i = 0; $i < $limit; $i++) {
            $points[] = $ring[$i];
            // Punto medio del segmento hacia el siguiente (más robusto que solo vértices)
            $j = ($i + 1) % $limit;
            $points[] = [
                ($ring[$i][0] + $ring[$j][0]) / 2,
                ($ring[$i][1] + $ring[$j][1]) / 2,
            ];
        }

        $points[] = $this->ringCentroid($ring);

        return $points;
    }

    /**
     * @param  list<list<array{0: float, 1: float}>>  $rings
     */
    private function pointInAnyRing(float $x, float $y, array $rings): bool
    {
        foreach ($rings as $ring) {
            if ($this->pointInRing($x, $y, $ring)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  array<string, mixed>  $geojson
     * @return list<list<array{0: float, 1: float}>>
     */
    private function extractOuterRings(array $geojson): array
    {
        $geometry = ($geojson['type'] ?? null) === 'Feature'
            ? ($geojson['geometry'] ?? [])
            : $geojson;

        $type = $geometry['type'] ?? null;
        $coords = $geometry['coordinates'] ?? null;
        if (! is_array($coords)) {
            return [];
        }

        if ($type === 'Polygon') {
            return [array_map(fn ($p) => [(float) $p[0], (float) $p[1]], $coords[0] ?? [])];
        }

        if ($type === 'MultiPolygon') {
            $rings = [];
            foreach ($coords as $poly) {
                $rings[] = array_map(fn ($p) => [(float) $p[0], (float) $p[1]], $poly[0] ?? []);
            }

            return $rings;
        }

        return [];
    }

    /**
     * @param  list<array{0: float, 1: float}>  $ring
     */
    private function ringAreaM2(array $ring): float
    {
        if (count($ring) < 3) {
            return 0.0;
        }

        $lat0 = $ring[0][1];
        $lon0 = $ring[0][0];
        $mPerDegLat = 111320.0;
        $mPerDegLon = 111320.0 * cos(deg2rad($lat0));

        $area = 0.0;
        $n = count($ring);
        for ($i = 0; $i < $n - 1; $i++) {
            $x1 = ($ring[$i][0] - $lon0) * $mPerDegLon;
            $y1 = ($ring[$i][1] - $lat0) * $mPerDegLat;
            $x2 = ($ring[$i + 1][0] - $lon0) * $mPerDegLon;
            $y2 = ($ring[$i + 1][1] - $lat0) * $mPerDegLat;
            $area += ($x1 * $y2) - ($x2 * $y1);
        }

        return $area / 2.0;
    }

    /**
     * @param  list<array{0: float, 1: float}>  $ring
     */
    private function ringPerimeterM(array $ring): float
    {
        $total = 0.0;
        $n = count($ring);
        for ($i = 0; $i < $n - 1; $i++) {
            $total += $this->haversineM($ring[$i][1], $ring[$i][0], $ring[$i + 1][1], $ring[$i + 1][0]);
        }

        return $total;
    }

    private function haversineM(float $lat1, float $lon1, float $lat2, float $lon2): float
    {
        $r = 6371000.0;
        $dLat = deg2rad($lat2 - $lat1);
        $dLon = deg2rad($lon2 - $lon1);
        $a = sin($dLat / 2) ** 2
            + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin($dLon / 2) ** 2;

        return 2 * $r * asin(min(1.0, sqrt($a)));
    }

    /**
     * @param  list<array{0: float, 1: float}>  $ring
     * @return array{0: float, 1: float}
     */
    private function ringCentroid(array $ring): array
    {
        $sumX = 0.0;
        $sumY = 0.0;
        $n = max(1, count($ring) - 1);
        for ($i = 0; $i < $n; $i++) {
            $sumX += $ring[$i][0];
            $sumY += $ring[$i][1];
        }

        return [$sumX / $n, $sumY / $n];
    }

    /**
     * @param  list<array{0: float, 1: float}>  $ring
     */
    private function pointInRing(float $x, float $y, array $ring): bool
    {
        $inside = false;
        $n = count($ring);
        for ($i = 0, $j = $n - 1; $i < $n; $j = $i++) {
            $xi = $ring[$i][0];
            $yi = $ring[$i][1];
            $xj = $ring[$j][0];
            $yj = $ring[$j][1];
            $intersect = (($yi > $y) !== ($yj > $y))
                && ($x < ($xj - $xi) * ($y - $yi) / (($yj - $yi) ?: 1e-12) + $xi);
            if ($intersect) {
                $inside = ! $inside;
            }
        }

        return $inside;
    }
}
