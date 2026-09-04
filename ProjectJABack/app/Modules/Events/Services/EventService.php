<?php

namespace App\Modules\Events\Services;

use App\Models\User;
use App\Modules\Cabanas\Models\EventoCabana;
use App\Modules\Events\Models\Event;
use App\Modules\Events\Models\TipoEvento;
use App\Modules\Lugares\Models\Lugar;
use App\Modules\Terrains\Models\EventoTerreno;
use App\Modules\Organizations\Models\Organizacion;
use App\Modules\Organizations\Models\TipoOrganizacion;
use App\Modules\Organizations\Services\OrganizationAccessService;
use App\Modules\Shared\Models\StoredFile;
use App\Modules\Shared\Services\AuditLogger;
use App\Modules\Shared\Services\ImageOptimizer;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class EventService
{
    public function __construct(
        private readonly AuditLogger $auditLogger,
        private readonly OrganizationAccessService $orgAccess,
        private readonly CriterioEvaluacionService $criterioService,
        private readonly ImageOptimizer $imageOptimizer,
    ) {}

    public function list(User $actor, array $filters = [], int $perPage = 15): LengthAwarePaginator
    {
        $query = Event::query()->with([
            'organizacion:id,nombre,codigo',
            'tipoEvento:id,nombre,slug,color,icono',
            'categoriaSubevento:id,nombre,slug,color,icono,orden,estado,maneja_puntos,maneja_fecha_inicio,maneja_fecha_fin',
            'organizaciones:id,nombre,codigo',
            'tiposOrganizacion:id,nombre',
            'padre:id,name',
            'jueces:id,name,email',
            'supervisores:id,name,email',
            'criterios:id,nombre,descripcion,estado,orden',
            'cuentaBancaria.qrFile',
            'catalogLugar:id,nombre,descripcion,latitud,longitud,nivel_zoom,estado',
        ])->withCount('hijos');

        $manageAll = $actor->hasPermission('events.create')
            || $actor->hasPermission('events.update')
            || $actor->hasPermission('events.delete');

        if (! $manageAll || $this->orgAccess->shouldScopeByOrganization($actor)) {
            if (! $this->orgAccess->bypassesOrganizationScope($actor)) {
                $query->visibleTo($actor);
            }
        }

        if (! empty($filters['q'])) {
            $q = trim((string) $filters['q']);
            $query->where('name', 'like', "%{$q}%");
        }

        if (array_key_exists('is_active', $filters) && $filters['is_active'] !== null && $filters['is_active'] !== '') {
            $query->where('is_active', filter_var($filters['is_active'], FILTER_VALIDATE_BOOLEAN));
        }

        if (! empty($filters['estado'])) {
            $query->where('estado', (string) $filters['estado']);
        }

        if (! empty($filters['tipo_evento_id'])) {
            $query->where('tipo_evento_id', (int) $filters['tipo_evento_id']);
        }

        if (! empty($filters['evento_padre_id'])) {
            $query->where('evento_padre_id', (int) $filters['evento_padre_id']);
        }

        $soloRaiz = array_key_exists('solo_raiz', $filters)
            && filter_var($filters['solo_raiz'] ?? false, FILTER_VALIDATE_BOOLEAN);

        if ($soloRaiz) {
            $query->whereNull('evento_padre_id');
        }

        $incluirArbolHijos = $soloRaiz
            || ! empty($filters['evento_padre_id'])
            || filter_var($filters['incluir_hijos'] ?? false, FILTER_VALIDATE_BOOLEAN);

        if ($incluirArbolHijos) {
            $this->eagerLoadHijosTree($query, 6);
        }

        if (! empty($filters['evento_padre_id'])) {
            return $query
                ->orderBy('orden')
                ->orderBy('id')
                ->paginate($perPage);
        }

        return $query
            ->orderByDesc('starts_at')
            ->paginate($perPage);
    }

    public function find(int $id): Event
    {
        $query = Event::query()
            ->with([
                'organizacion:id,nombre,codigo',
                'tipoEvento:id,nombre,slug,color,icono',
                'categoriaSubevento:id,nombre,slug,color,icono,orden,estado,maneja_puntos,maneja_fecha_inicio,maneja_fecha_fin',
                'organizaciones:id,nombre,codigo',
                'tiposOrganizacion:id,nombre',
                'padre:id,name,starts_at,ends_at,es_en_sitio,puntaje_maximo',
                'jueces:id,name,email',
                'supervisores:id,name,email',
                'criterios:id,nombre,descripcion,estado,orden',
                'cuentaBancaria.qrFile',
                'catalogLugar:id,nombre,descripcion,latitud,longitud,nivel_zoom,estado',
            ])
            ->withCount('hijos');

        $this->eagerLoadHijosTree($query, 6);

        return $query->findOrFail($id);
    }

    /**
     * Carga recursiva del árbol de hijos para UI en acordeón.
     */
    private function eagerLoadHijosTree(Builder|Relation $query, int $depth): void
    {
        if ($depth <= 0) {
            return;
        }

        $query->with([
            'hijos' => function (Relation $q) use ($depth) {
                $q->withCount('hijos')
                    ->with([
                        'tipoEvento:id,nombre,slug,color,icono',
                        'categoriaSubevento:id,nombre,slug,color,icono,orden,estado,maneja_puntos,maneja_fecha_inicio,maneja_fecha_fin',
                        'organizaciones:id,nombre,codigo',
                        'tiposOrganizacion:id,nombre',
                        'jueces:id,name,email',
                        'supervisores:id,name,email',
                        'criterios:id,nombre,descripcion,estado,orden',
                    ])
                    ->orderBy('orden')
                    ->orderBy('id');

                $this->eagerLoadHijosTree($q, $depth - 1);
            },
        ]);
    }

    /**
     * @return list<TipoEvento>
     */
    public function listTiposEvento(): array
    {
        return TipoEvento::query()
            ->where('estado', true)
            ->orderBy('orden')
            ->orderBy('nombre')
            ->get()
            ->all();
    }

    /**
     * Usuarios activos con un rol organizacional dado.
     *
     * @return list<User>
     */
    public function listUsersByRole(string $roleName): array
    {
        return User::query()
            ->where('is_active', true)
            ->whereHas('persona.organizaciones', function ($po) use ($roleName) {
                $po->where('estado', true)
                    ->whereHas('rolesAsignados.rol', fn ($r) => $r->where('name', $roleName));
            })
            ->orderBy('name')
            ->get(['id', 'name', 'email'])
            ->all();
    }

    /**
     * @return list<User>
     */
    public function listJueces(): array
    {
        return $this->listUsersByRole('juez');
    }

    /**
     * @return list<User>
     */
    public function listSupervisores(): array
    {
        return $this->listUsersByRole('supervisor');
    }

    /**
     * @param  list<int>  $orderedIds
     */
    public function reorderChildren(Event $parent, array $orderedIds, User $actor): void
    {
        $children = $parent->hijos()->pluck('id')->map(fn ($id) => (int) $id)->all();
        $orderedIds = array_values(array_unique(array_map('intval', $orderedIds)));
        sort($children);
        $sortedOrdered = $orderedIds;
        sort($sortedOrdered);
        if ($children !== $sortedOrdered) {
            throw ValidationException::withMessages([
                'orden' => ['La lista de subeventos no coincide con los hijos del evento.'],
            ]);
        }

        DB::transaction(function () use ($orderedIds, $parent) {
            foreach ($orderedIds as $index => $id) {
                Event::query()->where('id', $id)->where('evento_padre_id', $parent->id)->update([
                    'orden' => $index + 1,
                ]);
            }
            $this->auditLogger->log('events', 'reorder_children', null, [
                'evento_padre_id' => $parent->id,
                'orden' => $orderedIds,
            ], $parent);
        });
    }

    /**
     * Mueve un subevento bajo otro padre y lo posiciona (antes de beforeId o al final).
     */
    public function move(Event $event, User $actor, int $newParentId, ?int $beforeId = null): Event
    {
        if ((int) $event->id === $newParentId) {
            throw ValidationException::withMessages([
                'evento_padre_id' => ['Un evento no puede ser padre de sí mismo.'],
            ]);
        }

        if ($beforeId !== null && $beforeId === (int) $event->id) {
            $beforeId = null;
        }

        $this->assertHierarchyAndSiteDates([
            'evento_padre_id' => $newParentId,
            'starts_at' => $event->starts_at?->toDateTimeString(),
            'ends_at' => $event->ends_at?->toDateTimeString(),
            'tipo_evento_id' => $event->tipo_evento_id,
        ], $event);

        if ($beforeId !== null) {
            $before = Event::query()->find($beforeId);
            if (! $before || (int) $before->evento_padre_id !== $newParentId) {
                throw ValidationException::withMessages([
                    'before_id' => ['La posición de destino no pertenece al nuevo padre.'],
                ]);
            }
        }

        $oldParentId = $event->evento_padre_id ? (int) $event->evento_padre_id : null;

        return DB::transaction(function () use ($event, $newParentId, $beforeId, $oldParentId) {
            $old = $event->toArray();

            $siblings = Event::query()
                ->where('evento_padre_id', $newParentId)
                ->where('id', '!=', $event->id)
                ->orderBy('orden')
                ->orderBy('id')
                ->pluck('id')
                ->map(fn ($id) => (int) $id)
                ->all();

            $ordered = $siblings;
            if ($beforeId !== null) {
                $pos = array_search($beforeId, $ordered, true);
                if ($pos === false) {
                    $ordered[] = (int) $event->id;
                } else {
                    array_splice($ordered, $pos, 0, [(int) $event->id]);
                }
            } else {
                $ordered[] = (int) $event->id;
            }

            $event->update([
                'evento_padre_id' => $newParentId,
                'orden' => array_search((int) $event->id, $ordered, true) + 1,
            ]);

            foreach ($ordered as $index => $id) {
                Event::query()->where('id', $id)->update(['orden' => $index + 1]);
            }

            if ($oldParentId && $oldParentId !== $newParentId) {
                $oldParent = Event::query()->find($oldParentId);
                if ($oldParent) {
                    $this->syncAncestorScoresFromChildren($oldParent);
                }
            }

            $newParent = Event::query()->find($newParentId);
            if ($newParent) {
                $this->syncAncestorScoresFromChildren($newParent);
            }

            $event = $event->fresh([
                'organizacion:id,nombre,codigo',
                'tipoEvento:id,nombre,slug,color,icono',
                'categoriaSubevento:id,nombre,slug,color,icono',
                'organizaciones:id,nombre,codigo',
                'tiposOrganizacion:id,nombre',
                'padre:id,name',
            ]);

            $this->auditLogger->log('events', 'move', $old, $event?->toArray(), $event);

            return $event;
        });
    }

    public function create(User $actor, array $data): Event
    {
        $orgIds = $this->normalizeIds($data['organizacion_ids'] ?? []);
        $tipoIds = $this->resolveAudienceTipoIds($data);
        $juezIds = array_key_exists('juez_ids', $data)
            ? $this->normalizeIds($data['juez_ids'] ?? [])
            : null;
        $supervisorIds = array_key_exists('supervisor_ids', $data)
            ? $this->normalizeIds($data['supervisor_ids'] ?? [])
            : null;
        $hasCriterios = array_key_exists('criterios', $data);
        $criterios = $hasCriterios && is_array($data['criterios']) ? $data['criterios'] : [];
        unset(
            $data['organizacion_ids'],
            $data['tipo_organizacion_ids'],
            $data['audiencia'],
            $data['role_ids'],
            $data['juez_ids'],
            $data['supervisor_ids'],
            $data['juez_user_id'],
            $data['criterios'],
        );

        $this->assertValidDates((string) $data['starts_at'], (string) $data['ends_at']);
        $this->assertHierarchyAndSiteDates($data, null);
        $this->applyHomeOrganization($actor, $data, $orgIds);
        $this->assertActorCanAssignOrganizations($actor, $data['organizacion_id'] ?? null, $orgIds);
        if (is_array($juezIds)) {
            $this->assertUsersHaveRole($juezIds, 'juez', 'juez_ids');
        }
        if (is_array($supervisorIds)) {
            $this->assertUsersHaveRole($supervisorIds, 'supervisor', 'supervisor_ids');
        }

        return DB::transaction(function () use ($actor, $data, $orgIds, $tipoIds, $juezIds, $supervisorIds, $hasCriterios, $criterios) {
            $data['created_by'] = $actor->id;
            $data['is_active'] = $data['is_active'] ?? true;
            $data['estado'] = $data['estado'] ?? Event::ESTADO_BORRADOR;
            $data['es_en_sitio'] = $data['es_en_sitio'] ?? true;
            $data['es_calificable'] = $data['es_calificable'] ?? false;
            $data['tiene_subeventos'] = $data['tiene_subeventos'] ?? false;
            $data['puntaje_desde_hijos'] = $data['puntaje_desde_hijos'] ?? false;
            $data['requiere_pago'] = $data['requiere_pago'] ?? false;
            $data['requiere_seguro'] = $data['requiere_seguro'] ?? false;
            $data['cupo_ilimitado'] = $data['cupo_ilimitado'] ?? false;
            $data['permite_inscripcion_individual'] = $data['permite_inscripcion_individual'] ?? true;
            $data['permite_inscripcion_organizacion'] = $data['permite_inscripcion_organizacion'] ?? false;
            $data['permite_inscripcion_club'] = $data['permite_inscripcion_club'] ?? false;
            $data['permite_inscripcion_iglesia'] = $data['permite_inscripcion_iglesia'] ?? false;
            $data['usar_lotes'] = $data['usar_lotes'] ?? false;
            $data['usar_cabanas'] = $data['usar_cabanas'] ?? false;
            $this->applyLugarSnapshot($data);

            if (! empty($data['evento_padre_id']) && ! isset($data['orden'])) {
                $data['orden'] = (int) Event::query()
                    ->where('evento_padre_id', (int) $data['evento_padre_id'])
                    ->max('orden') + 1;
            } else {
                $data['orden'] = $data['orden'] ?? 0;
            }

            $event = Event::query()->create($data);
            $this->syncOrganizaciones($event, $orgIds);
            $this->syncTiposOrganizacion($event, $tipoIds);
            if (is_array($juezIds)) {
                $event->jueces()->sync($juezIds);
            }
            if (is_array($supervisorIds)) {
                $event->supervisores()->sync($supervisorIds);
            }
            if ($hasCriterios) {
                $this->criterioService->syncForEvent($event, $criterios);
            }

            if (! empty($data['puntaje_desde_hijos'])) {
                $this->syncScoreFromChildren($event);
            }
            $this->syncAncestorScoresFromChildren($event);

            $event = $event->fresh([
                'organizacion:id,nombre,codigo',
                'tipoEvento:id,nombre,slug,color,icono',
                'categoriaSubevento:id,nombre,slug,color,icono',
                'organizaciones:id,nombre,codigo',
                'tiposOrganizacion:id,nombre',
                'jueces:id,name,email',
                'supervisores:id,name,email',
                'criterios:id,nombre,descripcion,estado,orden',
                'cuentaBancaria.qrFile',
                'catalogLugar:id,nombre,descripcion,latitud,longitud,nivel_zoom,estado',
            ]);
            $this->auditLogger->log('events', 'create', null, $event->toArray(), $event);

            return $event;
        });
    }

    public function update(Event $event, User $actor, array $data): Event
    {
        $hasOrgs = array_key_exists('organizacion_ids', $data);
        $hasTipos = array_key_exists('tipo_organizacion_ids', $data)
            || array_key_exists('audiencia', $data);
        $hasJueces = array_key_exists('juez_ids', $data);
        $hasSupervisores = array_key_exists('supervisor_ids', $data);
        $hasCriterios = array_key_exists('criterios', $data);
        $orgIds = $hasOrgs ? $this->normalizeIds($data['organizacion_ids'] ?? []) : null;
        $tipoIds = $hasTipos ? $this->resolveAudienceTipoIds($data) : null;
        $juezIds = $hasJueces ? $this->normalizeIds($data['juez_ids'] ?? []) : null;
        $supervisorIds = $hasSupervisores ? $this->normalizeIds($data['supervisor_ids'] ?? []) : null;
        $criterios = $hasCriterios && is_array($data['criterios']) ? $data['criterios'] : [];
        unset(
            $data['organizacion_ids'],
            $data['tipo_organizacion_ids'],
            $data['audiencia'],
            $data['role_ids'],
            $data['juez_ids'],
            $data['supervisor_ids'],
            $data['juez_user_id'],
            $data['criterios'],
        );

        $startsAt = $data['starts_at'] ?? $event->starts_at?->toDateTimeString();
        $endsAt = $data['ends_at'] ?? $event->ends_at?->toDateTimeString();
        $this->assertValidDates((string) $startsAt, (string) $endsAt);

        $merged = array_merge($event->toArray(), $data);
        $this->assertHierarchyAndSiteDates($merged, $event);
        if ($hasJueces) {
            $this->assertUsersHaveRole($juezIds ?? [], 'juez', 'juez_ids');
        }
        if ($hasSupervisores) {
            $this->assertUsersHaveRole($supervisorIds ?? [], 'supervisor', 'supervisor_ids');
        }
        if (array_key_exists('lugar_id', $data)) {
            $this->assertLugarCompatible($event, $data['lugar_id'] !== null ? (int) $data['lugar_id'] : null);
            $this->applyLugarSnapshot($data);
        }

        if ($hasOrgs || array_key_exists('organizacion_id', $data)) {
            if (is_array($orgIds)) {
                $this->applyHomeOrganization($actor, $data, $orgIds);
            }
            $this->assertActorCanAssignOrganizations(
                $actor,
                $data['organizacion_id'] ?? $event->organizacion_id,
                $orgIds ?? $event->organizaciones()->pluck('organizacion.id')->map(fn ($id) => (int) $id)->all(),
            );
        }

        return DB::transaction(function () use ($event, $data, $hasOrgs, $orgIds, $hasTipos, $tipoIds, $hasJueces, $juezIds, $hasSupervisores, $supervisorIds, $hasCriterios, $criterios) {
            $old = $event->load(['organizaciones', 'tiposOrganizacion', 'jueces', 'supervisores', 'criterios'])->toArray();
            $event->update($data);

            if ($hasOrgs && is_array($orgIds)) {
                $this->syncOrganizaciones($event, $orgIds);
            }
            if ($hasTipos && is_array($tipoIds)) {
                $this->syncTiposOrganizacion($event, $tipoIds);
            }
            if ($hasJueces && is_array($juezIds)) {
                $event->jueces()->sync($juezIds);
            }
            if ($hasSupervisores && is_array($supervisorIds)) {
                $event->supervisores()->sync($supervisorIds);
            }
            if ($hasCriterios) {
                $this->criterioService->syncForEvent($event->fresh(), $criterios);
            }

            $event->refresh();
            if ($event->puntaje_desde_hijos) {
                $this->syncScoreFromChildren($event);
            }
            $this->syncAncestorScoresFromChildren($event);

            $event = $event->fresh([
                'organizacion:id,nombre,codigo',
                'tipoEvento:id,nombre,slug,color,icono',
                'categoriaSubevento:id,nombre,slug,color,icono',
                'organizaciones:id,nombre,codigo',
                'tiposOrganizacion:id,nombre',
                'padre:id,name',
                'jueces:id,name,email',
                'supervisores:id,name,email',
                'criterios:id,nombre,descripcion,estado,orden',
                'cuentaBancaria.qrFile',
                'catalogLugar:id,nombre,descripcion,latitud,longitud,nivel_zoom,estado',
            ]);
            $this->auditLogger->log('events', 'update', $old, $event->toArray(), $event);

            return $event;
        });
    }

    public function changeEstado(Event $event, User $actor, string $estado): Event
    {
        $allowed = [
            Event::ESTADO_BORRADOR,
            Event::ESTADO_PUBLICADO,
            Event::ESTADO_EN_PROCESO,
            Event::ESTADO_CERRADO,
        ];
        if (! in_array($estado, $allowed, true)) {
            throw ValidationException::withMessages([
                'estado' => ['El estado debe ser en preparación, activo, en proceso o finalizado.'],
            ]);
        }

        return $this->update($event, $actor, [
            'estado' => $estado,
            'is_active' => in_array($estado, [Event::ESTADO_PUBLICADO, Event::ESTADO_EN_PROCESO], true),
        ]);
    }

    public function delete(Event $event): void
    {
        if ($event->hijos()->exists()) {
            throw ValidationException::withMessages([
                'evento' => ['No puedes eliminar un evento que tiene subeventos. Elimina o reasigna los hijos primero.'],
            ]);
        }

        $parentId = $event->evento_padre_id;

        DB::transaction(function () use ($event) {
            $old = $event->load(['organizaciones', 'tiposOrganizacion'])->toArray();
            $event->delete();
            $this->auditLogger->log('events', 'delete', $old, null, $event);
        });

        if ($parentId) {
            $parent = Event::query()->find($parentId);
            if ($parent) {
                $this->syncAncestorScoresFromChildren($parent);
            }
        }
    }

    /**
     * Duplica un evento (y su árbol de hijos) como borrador, sin inscripciones ni calificaciones.
     *
     * @param  array{name?: string|null}  $options
     */
    public function duplicate(Event $source, User $actor, array $options = []): Event
    {
        $source = $this->find($source->id);

        return DB::transaction(function () use ($source, $actor, $options) {
            $clone = $this->cloneEventNode(
                $source,
                $actor,
                $source->evento_padre_id ? (int) $source->evento_padre_id : null,
                true,
                $options,
            );

            $this->syncAncestorScoresFromChildren($clone);
            $this->auditLogger->log('events', 'duplicate', ['source_id' => $source->id], $clone->toArray(), $clone);

            return $this->find($clone->id);
        });
    }

    /**
     * @param  array{name?: string|null}  $options
     */
    private function cloneEventNode(
        Event $source,
        User $actor,
        ?int $newParentId,
        bool $isEntryPoint,
        array $options = [],
    ): Event {
        $attrs = collect($source->getAttributes())
            ->except(['id', 'created_at', 'updated_at', 'deleted_at'])
            ->all();

        $attrs['evento_padre_id'] = $newParentId;
        $attrs['created_by'] = $actor->id;

        if ($isEntryPoint) {
            $attrs['name'] = trim((string) ($options['name'] ?? '')) !== ''
                ? (string) $options['name']
                : $source->name.' (copia)';
            $attrs['estado'] = Event::ESTADO_BORRADOR;
            // Si se duplica un hijo, queda como hermano bajo el mismo padre.
            if ($newParentId) {
                $attrs['orden'] = (int) Event::query()
                    ->where('evento_padre_id', $newParentId)
                    ->max('orden') + 1;
            }
        }

        $clone = Event::query()->create($attrs);

        if ($source->relationLoaded('organizaciones')) {
            $this->syncOrganizaciones(
                $clone,
                $source->organizaciones->pluck('id')->map(fn ($id) => (int) $id)->all(),
            );
        } else {
            $this->syncOrganizaciones(
                $clone,
                $source->organizaciones()->pluck('organizaciones.id')->map(fn ($id) => (int) $id)->all(),
            );
        }

        if ($source->relationLoaded('tiposOrganizacion')) {
            $this->syncTiposOrganizacion(
                $clone,
                $source->tiposOrganizacion->pluck('id')->map(fn ($id) => (int) $id)->all(),
            );
        } else {
            $this->syncTiposOrganizacion(
                $clone,
                $source->tiposOrganizacion()->pluck('tipos_organizacion.id')->map(fn ($id) => (int) $id)->all(),
            );
        }

        $juezIds = $source->ownJueces()->pluck('id')->map(fn ($id) => (int) $id)->all();
        $supervisorIds = $source->ownSupervisores()->pluck('id')->map(fn ($id) => (int) $id)->all();
        $clone->jueces()->sync($juezIds);
        $clone->supervisores()->sync($supervisorIds);

        $criterios = $source->relationLoaded('criterios')
            ? $source->criterios
            : $source->criterios()->get();

        if ($criterios->isNotEmpty()) {
            $this->criterioService->syncForEvent(
                $clone,
                $criterios->map(fn ($c) => [
                    'id' => (int) $c->id,
                    'puntos' => (float) $c->pivot->puntos,
                    'orden' => (int) $c->pivot->orden,
                ])->values()->all(),
            );
        }

        $hijos = $source->relationLoaded('hijos')
            ? $source->hijos
            : $source->hijos()->with([
                'organizaciones:id',
                'tiposOrganizacion:id',
                'jueces:id,name,email',
                'supervisores:id,name,email',
                'criterios:id,nombre,descripcion,estado,orden',
            ])->orderBy('orden')->orderBy('id')->get();

        foreach ($hijos as $hijo) {
            $this->cloneEventNode($hijo, $actor, (int) $clone->id, false, []);
        }

        return $clone;
    }

    public function storeImage(Event $event, UploadedFile $file, User $actor): Event
    {
        return $this->storeMedia($event, $file, $actor, 'image_url', 'evt', 'image');
    }

    public function storeBanner(Event $event, UploadedFile $file, User $actor): Event
    {
        return $this->storeMedia($event, $file, $actor, 'banner_url', 'banner', 'banner');
    }

    private function storeMedia(
        Event $event,
        UploadedFile $file,
        User $actor,
        string $column,
        string $prefix,
        string $auditAction,
    ): Event {
        $stored = $this->imageOptimizer->store($file, "events/{$event->id}", $prefix);
        $url = url('storage/'.$stored->path);

        StoredFile::query()->create([
            'name' => $file->getClientOriginalName(),
            'path' => $stored->path,
            'size' => $stored->size,
            'mime_type' => $stored->mime,
            'hash' => $stored->hash,
            'uploaded_by' => $actor->id,
        ]);

        $old = [$column => $event->{$column}];
        $event->update([$column => $url]);
        $this->auditLogger->log('events', $auditAction, $old, [$column => $url], $event);

        return $event->fresh([
            'organizacion:id,nombre,codigo',
            'tipoEvento:id,nombre,slug,color,icono',
            'categoriaSubevento:id,nombre,slug,color,icono',
            'organizaciones:id,nombre,codigo',
            'tiposOrganizacion:id,nombre',
        ]);
    }

    /** @param  array<string, mixed>  $data */
    private function applyLugarSnapshot(array &$data): void
    {
        if (! array_key_exists('lugar_id', $data) || $data['lugar_id'] === null || $data['lugar_id'] === '') {
            return;
        }

        $lugar = Lugar::query()->find((int) $data['lugar_id']);
        if (! $lugar) {
            return;
        }

        $data['lugar'] = $lugar->nombre;
        if (! array_key_exists('latitud', $data) || $data['latitud'] === null) {
            $data['latitud'] = $lugar->latitud;
        }
        if (! array_key_exists('longitud', $data) || $data['longitud'] === null) {
            $data['longitud'] = $lugar->longitud;
        }
    }

    private function assertLugarCompatible(Event $event, ?int $lugarId): void
    {
        $eventoTerreno = EventoTerreno::query()
            ->where('evento_id', $event->id)
            ->with('terreno:id,lugar_id')
            ->first();
        $hasCabanas = EventoCabana::query()->where('evento_id', $event->id)->exists();

        if (! $lugarId && ($eventoTerreno || $hasCabanas)) {
            throw ValidationException::withMessages([
                'lugar_id' => ['No se puede quitar el lugar mientras el evento tenga terreno o cabañas asociadas.'],
            ]);
        }

        if ($eventoTerreno?->terreno && $lugarId && (int) $eventoTerreno->terreno->lugar_id !== $lugarId) {
            throw ValidationException::withMessages([
                'lugar_id' => ['El evento ya tiene un terreno de otro lugar. Desasócielo antes de cambiar el lugar.'],
            ]);
        }

        $mismatch = $lugarId && EventoCabana::query()
            ->where('evento_id', $event->id)
            ->whereHas('cabana', fn ($q) => $q->where('lugar_id', '!=', $lugarId))
            ->exists();

        if ($mismatch) {
            throw ValidationException::withMessages([
                'lugar_id' => ['El evento ya tiene cabañas de otro lugar. Retírelas antes de cambiar el lugar.'],
            ]);
        }
    }

    /**
     * @param  list<int>  $orgIds
     */
    private function syncOrganizaciones(Event $event, array $orgIds): void
    {
        $event->organizaciones()->sync($orgIds);
    }

    /**
     * @param  list<int>  $tipoIds
     */
    private function syncTiposOrganizacion(Event $event, array $tipoIds): void
    {
        $event->tiposOrganizacion()->sync($tipoIds);
    }

    /**
     * @param  array<string, mixed>  $data
     * @return list<int>
     */
    private function resolveAudienceTipoIds(array $data): array
    {
        if (array_key_exists('audiencia', $data) && is_string($data['audiencia'])) {
            return $this->tipoIdsFromAudiencia($data['audiencia']);
        }

        return $this->remapTipoOrganizacionIds($this->normalizeIds($data['tipo_organizacion_ids'] ?? []));
    }

    /**
     * @return list<int>
     */
    private function tipoIdsFromAudiencia(string $audiencia): array
    {
        $key = strtolower(trim($audiencia));
        if ($key === '' || $key === 'libre') {
            return [];
        }

        $patterns = match ($key) {
            'conquistadores' => ['%conquistador%'],
            'aventureros' => ['%aventurer%'],
            'guias_mayores' => ['%gu_a%mayor%', '%guia%mayor%'],
            default => [],
        };
        if ($patterns === []) {
            return [];
        }

        $ids = TipoOrganizacion::query()
            ->where(function ($query) use ($patterns) {
                foreach ($patterns as $index => $like) {
                    if ($index === 0) {
                        $query->where('nombre', 'like', $like);
                    } else {
                        $query->orWhere('nombre', 'like', $like);
                    }
                }
            })
            ->pluck('id')
            ->map(fn ($id) => (int) $id)
            ->all();
        if ($ids !== []) {
            return array_values(array_unique($ids));
        }

        $fallback = [
            'conquistadores' => Organizacion::TIPO_CONQUISTADORES,
            'aventureros' => Organizacion::TIPO_AVENTUREROS,
            'guias_mayores' => Organizacion::TIPO_GUIAS_MAYORES,
        ][$key] ?? null;

        if ($fallback && TipoOrganizacion::query()->whereKey($fallback)->exists()) {
            return [(int) $fallback];
        }

        return [];
    }

    /**
     * @param  list<int>  $tipoIds
     * @return list<int>
     */
    private function remapTipoOrganizacionIds(array $tipoIds): array
    {
        if ($tipoIds === []) {
            return [];
        }

        $existing = TipoOrganizacion::query()
            ->whereIn('id', $tipoIds)
            ->pluck('id')
            ->map(fn ($id) => (int) $id)
            ->all();
        $missing = array_values(array_diff($tipoIds, $existing));
        if ($missing === []) {
            return $existing;
        }

        $fallback = [
            Organizacion::TIPO_AVENTUREROS => 'aventureros',
            Organizacion::TIPO_CONQUISTADORES => 'conquistadores',
            Organizacion::TIPO_GUIAS_MAYORES => 'guias_mayores',
        ];
        foreach ($missing as $id) {
            if (! isset($fallback[$id])) {
                continue;
            }
            $existing = array_merge($existing, $this->tipoIdsFromAudiencia($fallback[$id]));
        }

        return array_values(array_unique($existing));
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function assertHierarchyAndSiteDates(array $data, ?Event $current): void
    {
        $padreId = isset($data['evento_padre_id']) && $data['evento_padre_id'] !== null
            ? (int) $data['evento_padre_id']
            : null;

        if ($padreId === null) {
            return;
        }

        if ($current && $padreId === (int) $current->id) {
            throw ValidationException::withMessages([
                'evento_padre_id' => ['Un evento no puede ser padre de sí mismo.'],
            ]);
        }

        $padre = Event::query()->find($padreId);
        if (! $padre) {
            throw ValidationException::withMessages([
                'evento_padre_id' => ['El evento padre no existe.'],
            ]);
        }

        if ($padre->evento_padre_id === null && ! $padre->tiene_subeventos) {
            throw ValidationException::withMessages([
                'evento_padre_id' => ['Este evento no tiene habilitada la configuración de subeventos.'],
            ]);
        }

        if ($current) {
            $descendantIds = $this->descendantEventIds((int) $current->id);
            if (in_array($padreId, $descendantIds, true)) {
                throw ValidationException::withMessages([
                    'evento_padre_id' => ['No puedes asignar como padre a un subevento (ciclo).'],
                ]);
            }
        }

        if (! $padre->es_en_sitio) {
            return;
        }

        if ($this->allowsDatesOutsideParentRange($data)) {
            return;
        }

        $startsAt = strtotime((string) ($data['starts_at'] ?? ''));
        $endsAt = strtotime((string) ($data['ends_at'] ?? ''));
        $padreStart = $padre->starts_at?->getTimestamp();
        $padreEnd = $padre->ends_at?->getTimestamp();

        if ($padreStart === null || $padreEnd === null || $startsAt === false || $endsAt === false) {
            return;
        }

        if ($startsAt < $padreStart || $endsAt > $padreEnd) {
            throw ValidationException::withMessages([
                'starts_at' => ['Con el padre en sitio, las fechas del subevento deben estar dentro del rango del evento padre.'],
                'ends_at' => ['Con el padre en sitio, las fechas del subevento deben estar dentro del rango del evento padre.'],
            ]);
        }
    }

    /**
     * Precamporee / subeventos con fecha fin propia pueden ocurrir fuera del rango del evento principal.
     *
     * @param  array<string, mixed>  $data
     */
    private function allowsDatesOutsideParentRange(array $data): bool
    {
        if (! empty($data['maneja_fecha_fin'])) {
            return true;
        }

        $tipoId = isset($data['tipo_evento_id']) && $data['tipo_evento_id'] !== null
            ? (int) $data['tipo_evento_id']
            : null;

        if ($tipoId === null) {
            return false;
        }

        $slug = TipoEvento::query()
            ->where('id', $tipoId)
            ->value('slug');

        if (! is_string($slug) || $slug === '') {
            return false;
        }

        return str_contains(strtolower($slug), 'precamporee');
    }

    /**
     * @return list<int>
     */
    private function descendantEventIds(int $rootId): array
    {
        $ids = [];
        $frontier = [$rootId];
        while ($frontier !== []) {
            $children = Event::query()
                ->whereIn('evento_padre_id', $frontier)
                ->pluck('id')
                ->map(fn ($id) => (int) $id)
                ->all();
            if ($children === []) {
                break;
            }
            $ids = array_merge($ids, $children);
            $frontier = $children;
        }

        return array_values(array_unique($ids));
    }

    /**
     * @param  array<string, mixed>  $data
     * @param  list<int>  $orgIds
     */
    private function applyHomeOrganization(User $actor, array &$data, array &$orgIds): void
    {
        if ($this->orgAccess->bypassesOrganizationScope($actor)) {
            return;
        }

        $homeId = $this->orgAccess->homeOrganizationId($actor);
        if ($homeId === null) {
            return;
        }

        if (empty($data['organizacion_id'])) {
            $data['organizacion_id'] = $homeId;
        }

        if ($orgIds === []) {
            $orgIds = [$homeId];
        }
    }

    /**
     * @param  list<int>  $sharedOrgIds
     */
    private function assertActorCanAssignOrganizations(User $actor, mixed $organizadorId, array $sharedOrgIds): void
    {
        if ($this->orgAccess->bypassesOrganizationScope($actor)) {
            $all = array_filter(array_merge(
                $organizadorId !== null && $organizadorId !== '' ? [(int) $organizadorId] : [],
                $sharedOrgIds,
            ));
            if ($all !== []) {
                $existing = Organizacion::query()->whereIn('id', $all)->pluck('id')->map(fn ($id) => (int) $id)->all();
                $missing = array_values(array_diff($all, $existing));
                if ($missing !== []) {
                    throw ValidationException::withMessages([
                        'organizacion_ids' => ['Una o más organizaciones no existen.'],
                    ]);
                }
            }

            return;
        }

        $allowed = $this->orgAccess->accessibleOrganizationIds($actor);
        $check = array_values(array_unique(array_filter(array_merge(
            $organizadorId !== null && $organizadorId !== '' ? [(int) $organizadorId] : [],
            $sharedOrgIds,
        ))));

        $forbidden = array_values(array_diff($check, $allowed));
        if ($forbidden !== []) {
            throw ValidationException::withMessages([
                'organizacion_ids' => ['Solo puedes asociar el evento a organizaciones de tu alcance.'],
            ]);
        }
    }

    /**
     * @param  list<int>  $userIds
     */
    private function assertUsersHaveRole(array $userIds, string $roleName, string $field): void
    {
        if ($userIds === []) {
            return;
        }

        foreach ($userIds as $userId) {
            $user = User::query()->find($userId);
            if (! $user || ! $user->hasRole($roleName)) {
                throw ValidationException::withMessages([
                    $field => ["Todos los usuarios asignados deben tener el rol {$roleName}."],
                ]);
            }
        }
    }

    private function assertValidDates(string $startsAt, string $endsAt): void
    {
        if (strtotime($endsAt) < strtotime($startsAt)) {
            throw ValidationException::withMessages([
                'ends_at' => ['La fecha final debe ser posterior o igual a la fecha inicial.'],
            ]);
        }
    }

    /**
     * Suma el puntaje máximo de los hijos directos y lo asigna al evento.
     */
    public function syncScoreFromChildren(Event $event): void
    {
        $sum = (float) $event->hijos()->sum('puntaje_maximo');
        $event->forceFill([
            'puntaje_maximo' => $sum,
            'es_calificable' => true,
            'puntaje_desde_hijos' => true,
        ])->save();
    }

    /**
     * Recalcula hacia arriba los padres que traen puntaje desde hijos.
     */
    private function syncAncestorScoresFromChildren(Event $event): void
    {
        $current = $event;
        while ($current->evento_padre_id) {
            $parent = Event::query()->find($current->evento_padre_id);
            if (! $parent) {
                break;
            }
            if ($parent->puntaje_desde_hijos) {
                $this->syncScoreFromChildren($parent);
            }
            $current = $parent;
        }
    }

    /**
     * @param  list<int>|mixed  $ids
     * @return list<int>
     */
    private function normalizeIds(mixed $ids): array
    {
        if (! is_array($ids)) {
            return [];
        }

        return array_values(array_unique(array_map('intval', $ids)));
    }
}
