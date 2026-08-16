<?php

namespace App\Modules\Organizations\Services;

use App\Models\User;
use App\Modules\Organizations\Models\Organizacion;
use App\Modules\Organizations\Models\TipoOrganizacion;
use App\Modules\Shared\Services\AuditLogger;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class OrganizacionService
{
    public function __construct(
        private readonly AuditLogger $auditLogger,
        private readonly UbicacionService $ubicacionService,
        private readonly OrganizationAccessService $orgAccess,
        private readonly OrganizacionRealtimeNotifier $realtime,
    ) {}

    /**
     * @param  array<string, mixed>  $filters
     */
    public function list(array $filters = [], int $perPage = 15, ?User $actor = null): LengthAwarePaginator
    {
        $query = Organizacion::query()
            ->with([
                'tipo:id,nombre',
                'padre:id,nombre',
                'pais:id,nombre',
                'departamento:id,nombre',
                'ciudad:id,nombre',
                'departamentos:id,nombre,pais_id',
            ]);

        if ($actor && $this->orgAccess->shouldScopeByOrganization($actor)) {
            $orgIds = $this->orgAccess->accessibleOrganizationIds($actor);
            if ($orgIds === []) {
                $query->whereRaw('1 = 0');
            } else {
                $query->whereIn('id', $orgIds);
            }
        }

        if (! empty($filters['q'])) {
            $q = trim((string) $filters['q']);
            $query->where(function ($inner) use ($q) {
                $inner->where('nombre', 'like', "%{$q}%")
                    ->orWhere('codigo', 'like', "%{$q}%")
                    ->orWhere('correo', 'like', "%{$q}%")
                    ->orWhere('direccion', 'like', "%{$q}%");
            });
        }

        if (array_key_exists('estado', $filters) && $filters['estado'] !== null && $filters['estado'] !== '') {
            $query->where('estado', filter_var($filters['estado'], FILTER_VALIDATE_BOOLEAN));
        }

        if (! empty($filters['tipo_organizacion_id'])) {
            $query->where('tipo_organizacion_id', (int) $filters['tipo_organizacion_id']);
        }

        if (array_key_exists('organizacion_padre_id', $filters) && $filters['organizacion_padre_id'] !== null && $filters['organizacion_padre_id'] !== '') {
            if ($filters['organizacion_padre_id'] === 'null' || $filters['organizacion_padre_id'] === 'root') {
                $query->whereNull('organizacion_padre_id');
            } else {
                $query->where('organizacion_padre_id', (int) $filters['organizacion_padre_id']);
            }
        }

        return $query->orderBy('nombre')->paginate($perPage);
    }

    public function find(int $id): Organizacion
    {
        return Organizacion::query()
            ->with([
                'tipo:id,nombre,descripcion',
                'padre:id,nombre,pais_id,departamento_id,ciudad_id',
                'hijas:id,nombre,organizacion_padre_id',
                'pais:id,nombre',
                'departamento:id,nombre,pais_id',
                'ciudad:id,nombre,departamento_id',
                'departamentos:id,nombre,pais_id',
            ])
            ->findOrFail($id);
    }

    /**
     * @return list<TipoOrganizacion>
     */
    public function tipos(): array
    {
        return TipoOrganizacion::query()
            ->where('estado', true)
            ->orderBy('id')
            ->get()
            ->all();
    }

    /**
     * Árbol jerárquico de organizaciones.
     *
     * @param  array{q?: string|null, estado?: mixed, tipo_organizacion_id?: int|null}  $filters
     * @return list<array<string, mixed>>
     */
    public function tree(?int $excludeId = null, array $filters = [], ?User $actor = null): array
    {
        $query = Organizacion::query()
            ->with(['tipo:id,nombre', 'pais:id,nombre', 'departamento:id,nombre', 'ciudad:id,nombre'])
            ->orderBy('nombre');

        if ($actor && $this->orgAccess->shouldScopeByOrganization($actor)) {
            $orgIds = $this->orgAccess->accessibleOrganizationIds($actor);
            if ($orgIds === []) {
                return [];
            }
            $query->whereIn('id', $orgIds);
        }

        if ($excludeId) {
            $blocked = $this->descendantIds($excludeId);
            $blocked[] = $excludeId;
            $query->whereNotIn('id', $blocked);
        }

        $flat = $query->get([
            'id',
            'nombre',
            'codigo',
            'tipo_organizacion_id',
            'organizacion_padre_id',
            'estado',
            'pais_id',
            'departamento_id',
            'ciudad_id',
        ]);

        /** @var array<int, array<string, mixed>> $byId */
        $byId = [];
        foreach ($flat as $org) {
            $byId[(int) $org->id] = [
                'id' => $org->id,
                'nombre' => $org->nombre,
                'codigo' => $org->codigo,
                'tipo_organizacion_id' => $org->tipo_organizacion_id,
                'tipo_nombre' => $org->tipo?->nombre,
                'organizacion_padre_id' => $org->organizacion_padre_id,
                'estado' => (bool) $org->estado,
                'pais_nombre' => $org->pais?->nombre,
                'departamento_nombre' => $org->departamento?->nombre,
                'ciudad_nombre' => $org->ciudad?->nombre,
                'children' => [],
            ];
        }

        $hasFilters = $this->treeHasFilters($filters);
        if ($hasFilters) {
            $matchIds = $this->treeMatchingIds($byId, $filters);
            $keepIds = $this->treeIdsWithAncestors($byId, $matchIds);
            $byId = array_intersect_key($byId, array_flip($keepIds));
        }

        $roots = [];
        foreach ($byId as $id => &$node) {
            $padreId = $node['organizacion_padre_id'];
            if ($padreId && isset($byId[(int) $padreId])) {
                $byId[(int) $padreId]['children'][] = &$node;
            } else {
                $roots[] = &$node;
            }
        }
        unset($node);

        return $roots;
    }

    /**
     * @param  array{q?: string|null, estado?: mixed, tipo_organizacion_id?: int|null}  $filters
     */
    private function treeHasFilters(array $filters): bool
    {
        if (! empty($filters['q']) && trim((string) $filters['q']) !== '') {
            return true;
        }
        if (! empty($filters['tipo_organizacion_id'])) {
            return true;
        }
        if (array_key_exists('estado', $filters) && $filters['estado'] !== null && $filters['estado'] !== '') {
            return true;
        }

        return false;
    }

    /**
     * @param  array<int, array<string, mixed>>  $byId
     * @param  array{q?: string|null, estado?: mixed, tipo_organizacion_id?: int|null}  $filters
     * @return list<int>
     */
    private function treeMatchingIds(array $byId, array $filters): array
    {
        $q = ! empty($filters['q']) ? mb_strtolower(trim((string) $filters['q'])) : '';
        $tipoId = ! empty($filters['tipo_organizacion_id']) ? (int) $filters['tipo_organizacion_id'] : null;
        $estadoFilter = array_key_exists('estado', $filters) && $filters['estado'] !== null && $filters['estado'] !== ''
            ? filter_var($filters['estado'], FILTER_VALIDATE_BOOLEAN)
            : null;

        $matches = [];
        foreach ($byId as $id => $node) {
            if ($tipoId !== null && (int) $node['tipo_organizacion_id'] !== $tipoId) {
                continue;
            }
            if ($estadoFilter !== null && (bool) $node['estado'] !== $estadoFilter) {
                continue;
            }
            if ($q !== '') {
                $haystack = mb_strtolower(implode(' ', array_filter([
                    (string) $node['nombre'],
                    (string) ($node['codigo'] ?? ''),
                    (string) ($node['tipo_nombre'] ?? ''),
                    (string) ($node['pais_nombre'] ?? ''),
                    (string) ($node['departamento_nombre'] ?? ''),
                    (string) ($node['ciudad_nombre'] ?? ''),
                ])));
                if (! str_contains($haystack, $q)) {
                    continue;
                }
            }
            $matches[] = (int) $id;
        }

        return $matches;
    }

    /**
     * @param  array<int, array<string, mixed>>  $byId
     * @param  list<int>  $matchIds
     * @return list<int>
     */
    private function treeIdsWithAncestors(array $byId, array $matchIds): array
    {
        $keep = [];
        foreach ($matchIds as $id) {
            $current = $id;
            while ($current && isset($byId[$current]) && ! isset($keep[$current])) {
                $keep[$current] = true;
                $padreId = $byId[$current]['organizacion_padre_id'] ?? null;
                $current = $padreId ? (int) $padreId : 0;
            }
        }

        return array_map('intval', array_keys($keep));
    }

    /**
     * Opciones para selector de organización padre (excluye la propia y sus descendientes).
     * Si se indica $tipoHijoId, solo retorna organizaciones del tipo padre esperado.
     *
     * @return list<Organizacion>
     */
    public function parentOptions(?int $excludeId = null, ?int $tipoHijoId = null): array
    {
        $query = Organizacion::query()
            ->with([
                'tipo:id,nombre',
                'pais:id,nombre',
                'departamento:id,nombre',
                'ciudad:id,nombre',
                'departamentos:id,nombre,pais_id',
            ])
            ->where('estado', true)
            ->orderBy('nombre');

        if ($excludeId) {
            $blocked = $this->descendantIds($excludeId);
            $blocked[] = $excludeId;
            $query->whereNotIn('id', $blocked);
        }

        if ($tipoHijoId) {
            $tipoHijo = TipoOrganizacion::query()->find($tipoHijoId);
            if (! $tipoHijo) {
                return [];
            }
            if ($tipoHijo->tipo_organizacion_padre_id === null) {
                return [];
            }
            $query->where('tipo_organizacion_id', $tipoHijo->tipo_organizacion_padre_id);
        }

        return $query->get([
            'id',
            'nombre',
            'codigo',
            'tipo_organizacion_id',
            'organizacion_padre_id',
            'pais_id',
            'departamento_id',
            'ciudad_id',
        ])->all();
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function create(array $data): Organizacion
    {
        $padreId = isset($data['organizacion_padre_id']) ? (int) $data['organizacion_padre_id'] : null;
        $tipoId = (int) $data['tipo_organizacion_id'];
        $this->assertValidParent($padreId);
        $this->assertParentMatchesTipo($tipoId, $padreId);

        $created = DB::transaction(function () use ($data, $padreId, $tipoId) {
            $location = $this->resolveLocation($data, $padreId);
            $codigo = $this->generateCodigo($tipoId, $padreId);

            $org = Organizacion::query()->create([
                'organizacion_padre_id' => $padreId,
                'tipo_organizacion_id' => $data['tipo_organizacion_id'],
                'pais_id' => $location['pais_id'],
                'departamento_id' => $location['departamento_id'],
                'ciudad_id' => $location['ciudad_id'],
                'nombre' => $data['nombre'],
                'codigo' => $codigo,
                'direccion' => $location['direccion'],
                'telefono' => $data['telefono'] ?? null,
                'correo' => $data['correo'] ?? null,
                'estado' => $data['estado'] ?? true,
            ]);

            $this->syncDepartamentosCobertura($org, $data, $location['pais_id'] ? (int) $location['pais_id'] : null);

            $this->auditLogger->log('organizaciones', 'create', null, $org->toArray(), $org);

            return $this->find($org->id);
        });

        $this->realtime->notify('created', (int) $created->id);

        return $created;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function update(Organizacion $organizacion, array $data): Organizacion
    {
        // El tipo no se puede cambiar al editar; el padre sí, solo entre orgs del nivel superior.
        unset($data['tipo_organizacion_id']);

        $tipoId = (int) $organizacion->tipo_organizacion_id;
        $padreId = array_key_exists('organizacion_padre_id', $data)
            ? ($data['organizacion_padre_id'] !== null ? (int) $data['organizacion_padre_id'] : null)
            : ($organizacion->organizacion_padre_id !== null ? (int) $organizacion->organizacion_padre_id : null);

        $this->assertValidParent($padreId, (int) $organizacion->id);
        $this->assertParentMatchesTipo($tipoId, $padreId);

        $updated = DB::transaction(function () use ($organizacion, $data, $padreId, $tipoId) {
            $merged = array_merge($organizacion->toArray(), $data);
            $merged['tipo_organizacion_id'] = $tipoId;
            $merged['organizacion_padre_id'] = $padreId;
            $location = $this->resolveLocation($merged, $padreId);

            $old = $organizacion->toArray();
            $organizacion->fill([
                'organizacion_padre_id' => $padreId,
                'tipo_organizacion_id' => $tipoId,
                'pais_id' => $location['pais_id'],
                'departamento_id' => $location['departamento_id'],
                'ciudad_id' => $location['ciudad_id'],
                'nombre' => $data['nombre'] ?? $organizacion->nombre,
                'codigo' => $organizacion->codigo,
                'direccion' => $location['direccion'],
                'telefono' => array_key_exists('telefono', $data) ? $data['telefono'] : $organizacion->telefono,
                'correo' => array_key_exists('correo', $data) ? $data['correo'] : $organizacion->correo,
                'estado' => array_key_exists('estado', $data) ? (bool) $data['estado'] : $organizacion->estado,
            ])->save();

            $this->syncDepartamentosCobertura($organizacion, $data, $location['pais_id'] ? (int) $location['pais_id'] : null);

            $this->auditLogger->log('organizaciones', 'update', $old, $organizacion->fresh()->toArray(), $organizacion);

            return $this->find($organizacion->id);
        });

        $this->realtime->notify('updated', (int) $updated->id);

        return $updated;
    }

    public function delete(Organizacion $organizacion): void
    {
        if ($organizacion->hijas()->exists()) {
            throw ValidationException::withMessages([
                'organizacion' => ['No se puede eliminar: tiene organizaciones hijas. Reasigna o elimina las hijas primero.'],
            ]);
        }

        $id = (int) $organizacion->id;
        $old = $organizacion->toArray();
        $organizacion->delete();
        $this->auditLogger->log('organizaciones', 'delete', $old, null, $organizacion);
        $this->realtime->notify('deleted', $id);
    }

    /**
     * Resuelve país/departamento/ciudad según el tipo de organización.
     *
     * Unión: selecciona país.
     * Asociación: hereda país y cubre uno o varios departamentos.
     * Distrito: hereda país, elige un departamento de la asociación y una ciudad.
     * Iglesia/Club: heredan toda la ubicación del padre; Iglesia puede definir dirección.
     *
     * @param  array<string, mixed>  $data
     * @return array{pais_id: int|null, departamento_id: int|null, ciudad_id: int|null, direccion: string|null}
     */
    private function resolveLocation(array $data, ?int $padreId): array
    {
        $tipoId = (int) $data['tipo_organizacion_id'];
        $tipo = TipoOrganizacion::query()->find($tipoId);
        if (! $tipo) {
            throw ValidationException::withMessages([
                'tipo_organizacion_id' => ['Tipo de organización no válido.'],
            ]);
        }

        $padre = $padreId ? Organizacion::query()->find($padreId) : null;

        if (! $tipo->esRaiz() && ! $padre) {
            throw ValidationException::withMessages([
                'organizacion_padre_id' => ['Debes seleccionar la organización padre.'],
            ]);
        }

        if ($tipo->esRaiz() && $padre) {
            throw ValidationException::withMessages([
                'organizacion_padre_id' => ['Este tipo de organización no admite padre.'],
            ]);
        }

        return match ($tipoId) {
            Organizacion::TIPO_UNION => $this->resolveUnionLocation($data),
            Organizacion::TIPO_ASOCIACION => $this->resolveAsociacionLocation($data, $padre),
            Organizacion::TIPO_DISTRITO => $this->resolveDistritoLocation($data, $padre),
            Organizacion::TIPO_IGLESIA => $this->resolveInheritedLocation($data, $padre, true),
            Organizacion::TIPO_CLUB,
            Organizacion::TIPO_AVENTUREROS,
            Organizacion::TIPO_CONQUISTADORES,
            Organizacion::TIPO_GUIAS_MAYORES => $this->resolveInheritedLocation($data, $padre, false),
            default => $this->resolveByTipoHierarchy($tipo, $data, $padre),
        };
    }

    /**
     * Fallback para tipos hijos de Club (u otros) sin constante fija.
     *
     * @param  array<string, mixed>  $data
     * @return array{pais_id: int|null, departamento_id: int|null, ciudad_id: int|null, direccion: string|null}
     */
    private function resolveByTipoHierarchy(TipoOrganizacion $tipo, array $data, ?Organizacion $padre): array
    {
        if ((int) $tipo->tipo_organizacion_padre_id === Organizacion::TIPO_CLUB) {
            return $this->resolveInheritedLocation($data, $padre, false);
        }

        throw ValidationException::withMessages([
            'tipo_organizacion_id' => ['Tipo de organización no soportado.'],
        ]);
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array{pais_id: int|null, departamento_id: int|null, ciudad_id: int|null, direccion: string|null}
     */
    private function resolveUnionLocation(array $data): array
    {
        $pais = $this->ubicacionService->resolvePais(
            isset($data['pais_id']) ? (int) $data['pais_id'] : null,
            isset($data['pais_nombre']) ? (string) $data['pais_nombre'] : null,
        );

        if (! $pais) {
            throw ValidationException::withMessages([
                'pais_id' => ['La Unión debe indicar el país.'],
            ]);
        }

        return [
            'pais_id' => $pais->id,
            'departamento_id' => null,
            'ciudad_id' => null,
            'direccion' => null,
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array{pais_id: int|null, departamento_id: int|null, ciudad_id: int|null, direccion: string|null}
     */
    private function resolveAsociacionLocation(array $data, ?Organizacion $padre): array
    {
        if (! $padre?->pais_id) {
            throw ValidationException::withMessages([
                'organizacion_padre_id' => ['La Asociación debe pertenecer a una Unión con país definido.'],
            ]);
        }

        $departamentoIds = $this->normalizeDepartamentoIds($data, (int) $padre->pais_id);
        if ($departamentoIds === []) {
            throw ValidationException::withMessages([
                'departamento_ids' => ['La Asociación debe indicar al menos un departamento.'],
            ]);
        }

        return [
            'pais_id' => (int) $padre->pais_id,
            'departamento_id' => null,
            'ciudad_id' => null,
            'direccion' => null,
            'departamento_ids' => $departamentoIds,
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array{pais_id: int|null, departamento_id: int|null, ciudad_id: int|null, direccion: string|null}
     */
    private function resolveDistritoLocation(array $data, ?Organizacion $padre): array
    {
        if (! $padre?->pais_id) {
            throw ValidationException::withMessages([
                'organizacion_padre_id' => ['El Distrito debe pertenecer a una Asociación con país definido.'],
            ]);
        }

        $padre->loadMissing('departamentos:id,nombre,pais_id');
        $permitidos = $padre->departamentos->pluck('id')->map(fn ($id) => (int) $id)->all();

        // Compatibilidad: si la asociación aún no tiene pivot, usa su departamento_id histórico.
        if ($permitidos === [] && $padre->departamento_id) {
            $permitidos = [(int) $padre->departamento_id];
        }

        if ($permitidos === []) {
            throw ValidationException::withMessages([
                'organizacion_padre_id' => ['La Asociación padre no tiene departamentos asignados.'],
            ]);
        }

        $departamento = $this->ubicacionService->resolveDepartamento(
            (int) $padre->pais_id,
            isset($data['departamento_id']) ? (int) $data['departamento_id'] : null,
            isset($data['departamento_nombre']) ? (string) $data['departamento_nombre'] : null,
        );

        if (! $departamento) {
            throw ValidationException::withMessages([
                'departamento_id' => ['El Distrito debe indicar el departamento.'],
            ]);
        }

        if (! in_array((int) $departamento->id, $permitidos, true)) {
            throw ValidationException::withMessages([
                'departamento_id' => ['El departamento debe pertenecer a la Asociación padre.'],
            ]);
        }

        $ciudad = $this->ubicacionService->resolveCiudad(
            (int) $departamento->id,
            isset($data['ciudad_id']) ? (int) $data['ciudad_id'] : null,
            isset($data['ciudad_nombre']) ? (string) $data['ciudad_nombre'] : null,
        );

        if (! $ciudad) {
            throw ValidationException::withMessages([
                'ciudad_id' => ['El Distrito debe indicar la ciudad.'],
            ]);
        }

        return [
            'pais_id' => (int) $padre->pais_id,
            'departamento_id' => (int) $departamento->id,
            'ciudad_id' => $ciudad->id,
            'direccion' => null,
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array{pais_id: int|null, departamento_id: int|null, ciudad_id: int|null, direccion: string|null}
     */
    private function resolveInheritedLocation(array $data, ?Organizacion $padre, bool $allowDireccion): array
    {
        if (! $padre) {
            throw ValidationException::withMessages([
                'organizacion_padre_id' => ['Debes seleccionar la organización padre.'],
            ]);
        }

        if (! $padre->pais_id || ! $padre->departamento_id || ! $padre->ciudad_id) {
            throw ValidationException::withMessages([
                'organizacion_padre_id' => ['El padre debe tener país, departamento y ciudad definidos.'],
            ]);
        }

        $direccion = null;
        if ($allowDireccion) {
            $direccion = array_key_exists('direccion', $data)
                ? ($data['direccion'] !== null ? trim((string) $data['direccion']) : null)
                : null;
            if ($direccion === '') {
                $direccion = null;
            }
            if ($direccion === null) {
                throw ValidationException::withMessages([
                    'direccion' => ['La Iglesia debe indicar la dirección.'],
                ]);
            }
        }

        return [
            'pais_id' => (int) $padre->pais_id,
            'departamento_id' => (int) $padre->departamento_id,
            'ciudad_id' => (int) $padre->ciudad_id,
            'direccion' => $direccion,
        ];
    }

    /**
     * Genera un código único según el tipo y el padre.
     * Unión: UNI-0001
     * Hijos: {codigoPadre}-ASO-01, {codigoPadre}-DIS-01, etc.
     */
    private function generateCodigo(int $tipoId, ?int $padreId): string
    {
        $prefix = $this->codigoPrefixForTipo($tipoId);
        $padreCodigo = null;

        if ($padreId) {
            $padreCodigo = Organizacion::query()->where('id', $padreId)->value('codigo');
        }

        $base = $padreCodigo
            ? sprintf('%s-%s-', (string) $padreCodigo, $prefix)
            : sprintf('%s-', $prefix);

        $pad = $padreCodigo ? 2 : 4;
        $pattern = $base.'%';

        $existing = Organizacion::query()
            ->where('codigo', 'like', $pattern)
            ->pluck('codigo')
            ->all();

        $max = 0;
        $regex = '/^'.preg_quote($base, '/').'(\d+)$/';
        foreach ($existing as $codigo) {
            if (preg_match($regex, (string) $codigo, $matches)) {
                $max = max($max, (int) $matches[1]);
            }
        }

        $next = $max + 1;

        do {
            $candidate = $base.str_pad((string) $next, $pad, '0', STR_PAD_LEFT);
            $exists = Organizacion::query()->where('codigo', $candidate)->exists();
            $next++;
        } while ($exists);

        return $candidate;
    }

    private function codigoPrefixForTipo(int $tipoId): string
    {
        return match ($tipoId) {
            Organizacion::TIPO_UNION => 'UNI',
            Organizacion::TIPO_ASOCIACION => 'ASO',
            Organizacion::TIPO_DISTRITO => 'DIS',
            Organizacion::TIPO_IGLESIA => 'IGL',
            Organizacion::TIPO_CLUB => 'CLB',
            Organizacion::TIPO_AVENTUREROS => 'AVE',
            Organizacion::TIPO_CONQUISTADORES => 'CON',
            Organizacion::TIPO_GUIAS_MAYORES => 'GUI',
            default => 'ORG',
        };
    }

    /**
     * Sincroniza departamentos cubiertos (Asociación). Otros tipos limpian el pivot.
     *
     * @param  array<string, mixed>  $data
     */
    private function syncDepartamentosCobertura(Organizacion $organizacion, array $data, ?int $paisId): void
    {
        if ((int) $organizacion->tipo_organizacion_id !== Organizacion::TIPO_ASOCIACION) {
            $organizacion->departamentos()->sync([]);

            return;
        }

        if (! $paisId) {
            throw ValidationException::withMessages([
                'pais_id' => ['La Asociación requiere un país para asignar departamentos.'],
            ]);
        }

        $ids = $this->normalizeDepartamentoIds($data, $paisId);
        if ($ids === []) {
            throw ValidationException::withMessages([
                'departamento_ids' => ['La Asociación debe indicar al menos un departamento.'],
            ]);
        }

        $organizacion->departamentos()->sync($ids);
    }

    /**
     * @param  array<string, mixed>  $data
     * @return list<int>
     */
    private function normalizeDepartamentoIds(array $data, int $paisId): array
    {
        $ids = [];

        if (! empty($data['departamento_ids']) && is_array($data['departamento_ids'])) {
            foreach ($data['departamento_ids'] as $rawId) {
                $departamento = $this->ubicacionService->resolveDepartamento($paisId, (int) $rawId, null);
                if ($departamento) {
                    $ids[] = (int) $departamento->id;
                }
            }
        }

        if (! empty($data['departamento_nombres']) && is_array($data['departamento_nombres'])) {
            foreach ($data['departamento_nombres'] as $nombre) {
                $departamento = $this->ubicacionService->resolveDepartamento($paisId, null, (string) $nombre);
                if ($departamento) {
                    $ids[] = (int) $departamento->id;
                }
            }
        }

        // Compatibilidad con un solo departamento.
        if ($ids === [] && (! empty($data['departamento_id']) || ! empty($data['departamento_nombre']))) {
            $departamento = $this->ubicacionService->resolveDepartamento(
                $paisId,
                isset($data['departamento_id']) ? (int) $data['departamento_id'] : null,
                isset($data['departamento_nombre']) ? (string) $data['departamento_nombre'] : null,
            );
            if ($departamento) {
                $ids[] = (int) $departamento->id;
            }
        }

        return array_values(array_unique($ids));
    }

    private function assertValidParent(?int $padreId, ?int $currentId = null): void
    {
        if ($padreId === null) {
            return;
        }

        if ($currentId !== null && $padreId === $currentId) {
            throw ValidationException::withMessages([
                'organizacion_padre_id' => ['Una organización no puede ser padre de sí misma.'],
            ]);
        }

        $padre = Organizacion::query()->find($padreId);
        if (! $padre) {
            throw ValidationException::withMessages([
                'organizacion_padre_id' => ['La organización padre no existe.'],
            ]);
        }

        if ($currentId !== null && in_array($padreId, $this->descendantIds($currentId), true)) {
            throw ValidationException::withMessages([
                'organizacion_padre_id' => ['No puedes asignar como padre a una organización hija (ciclo).'],
            ]);
        }
    }

    private function assertParentMatchesTipo(int $tipoHijoId, ?int $padreId): void
    {
        $tipo = TipoOrganizacion::query()->find($tipoHijoId);
        if (! $tipo) {
            throw ValidationException::withMessages([
                'tipo_organizacion_id' => ['Tipo de organización no válido.'],
            ]);
        }

        if ($tipo->esRaiz()) {
            if ($padreId !== null) {
                throw ValidationException::withMessages([
                    'organizacion_padre_id' => ['Este tipo de organización no admite padre.'],
                ]);
            }

            return;
        }

        if ($padreId === null) {
            throw ValidationException::withMessages([
                'organizacion_padre_id' => ['Debes seleccionar la organización padre.'],
            ]);
        }

        $padre = Organizacion::query()->find($padreId);
        if (! $padre) {
            throw ValidationException::withMessages([
                'organizacion_padre_id' => ['La organización padre no existe.'],
            ]);
        }

        if ((int) $padre->tipo_organizacion_id !== (int) $tipo->tipo_organizacion_padre_id) {
            $tipoPadreNombre = TipoOrganizacion::query()
                ->where('id', $tipo->tipo_organizacion_padre_id)
                ->value('nombre') ?? 'padre esperado';

            throw ValidationException::withMessages([
                'organizacion_padre_id' => ["El padre debe ser de tipo {$tipoPadreNombre}."],
            ]);
        }
    }

    /**
     * @return list<int>
     */
    private function descendantIds(int $rootId): array
    {
        $ids = [];
        $frontier = [$rootId];

        while ($frontier !== []) {
            $children = Organizacion::query()
                ->whereIn('organizacion_padre_id', $frontier)
                ->pluck('id')
                ->all();

            $new = array_values(array_diff($children, $ids));
            if ($new === []) {
                break;
            }
            $ids = array_merge($ids, $new);
            $frontier = $new;
        }

        return $ids;
    }
}
