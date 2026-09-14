<?php

namespace App\Modules\Users\Services;

use App\Models\User;
use App\Modules\Shared\Services\AuditLogger;
use App\Modules\Users\Models\Page;
use App\Modules\Users\Models\Permission;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

final class PageService
{
    public function __construct(private readonly AuditLogger $auditLogger) {}

    /**
     * @return list<Page>
     */
    public function list(): array
    {
        return Page::query()
            ->with(['permissions' => fn ($q) => $q->orderBy('sort_order')])
            ->orderBy('sort_order')
            ->orderBy('name')
            ->get()
            ->all();
    }

    /**
     * @return list<Page>
     */
    public function menuFor(User $actor, ?string $front): array
    {
        $fronts = Page::frontsForClient($front);

        return Page::query()
            ->where('is_active', true)
            ->whereIn('front', $fronts)
            ->with(['permissions' => fn ($q) => $q->orderBy('sort_order')])
            ->orderBy('sort_order')
            ->orderBy('name')
            ->get()
            ->filter(fn (Page $page) => $this->actorCanSeePage($actor, $page))
            ->values()
            ->all();
    }

    public function create(array $data): Page
    {
        return DB::transaction(function () use ($data) {
            $data['key'] = Str::slug((string) $data['key'], '_');
            $data['front'] = $data['front'] ?? Page::FRONT_PROJECT;
            $data['is_active'] = array_key_exists('is_active', $data)
                ? (bool) $data['is_active']
                : true;
            $data['sort_order'] = $data['sort_order'] ?? ((int) Page::query()->max('sort_order') + 10);

            $page = Page::query()->create($data);
            Permission::query()->create([
                'name' => $page->key.'.view',
                'display_name' => 'Ver '.$page->name,
                'module' => $page->key,
                'page_id' => $page->id,
                'action' => 'view',
                'sort_order' => 1,
            ]);
            $this->forgetPermissionCaches();
            $this->auditLogger->log('pages', 'create', null, $page->toArray(), $page);

            return $page->fresh(['permissions']);
        });
    }

    public function update(Page $page, array $data): Page
    {
        $old = $page->toArray();
        if (isset($data['key']) && $page->isSystem() && $data['key'] !== $page->key) {
            throw ValidationException::withMessages([
                'key' => ['No puedes cambiar la clave de una página de sistema.'],
            ]);
        }

        if (isset($data['key'])) {
            $data['key'] = Str::slug((string) $data['key'], '_');
        }

        $page->fill($data);
        $page->save();
        $this->auditLogger->log('pages', 'update', $old, $page->toArray(), $page);

        return $page->fresh(['permissions']);
    }

    public function delete(Page $page): void
    {
        if ($page->isSystem()) {
            throw ValidationException::withMessages([
                'page' => ['No puedes eliminar una página de sistema.'],
            ]);
        }

        $old = $page->toArray();
        $page->permissions()->delete();
        $page->delete();
        $this->forgetPermissionCaches();
        $this->auditLogger->log('pages', 'delete', $old, null, $page);
    }

    private function actorCanSeePage(User $actor, Page $page): bool
    {
        if ($actor->isSuperAdmin() && $actor->active_organizacion_id === null) {
            return true;
        }

        foreach ($page->permissions as $permission) {
            if ($actor->hasPermission($permission->name)) {
                return true;
            }
        }

        return false;
    }

    private function forgetPermissionCaches(): void
    {
        Cache::forget('permissions:all:names');
    }
}
