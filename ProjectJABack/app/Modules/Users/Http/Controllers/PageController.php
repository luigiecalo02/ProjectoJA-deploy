<?php

namespace App\Modules\Users\Http\Controllers;

use App\Modules\Shared\Http\Responses\ApiResponse;
use App\Modules\Users\Models\Page;
use App\Modules\Users\Services\PageService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\Response;

final class PageController
{
    public function __construct(private readonly PageService $pageService) {}

    public function index(Request $request): JsonResponse
    {
        abort_unless($request->user()?->hasPermission('roles.update'), Response::HTTP_FORBIDDEN);

        return ApiResponse::success(array_map(
            fn (Page $page) => $this->payload($page),
            $this->pageService->list(),
        ));
    }

    public function menu(Request $request): JsonResponse
    {
        $front = $request->header('X-Clubes-Client') === 'clubes'
            ? Page::FRONT_CLUBES
            : (string) $request->query('front', Page::FRONT_PROJECT);

        return ApiResponse::success(array_map(
            fn (Page $page) => $this->menuPayload($page),
            $this->pageService->menuFor($request->user(), $front),
        ));
    }

    public function store(Request $request): JsonResponse
    {
        abort_unless($request->user()?->hasPermission('roles.update'), Response::HTTP_FORBIDDEN);

        $data = $request->validate([
            'key' => ['required', 'string', 'max:80', 'alpha_dash', 'unique:pages,key'],
            'name' => ['required', 'string', 'max:255'],
            'route_name' => ['nullable', 'string', 'max:120'],
            'icon' => ['nullable', 'string', 'max:100'],
            'sort_order' => ['nullable', 'integer', 'min:0'],
            'is_active' => ['sometimes', 'boolean'],
            'front' => ['required', 'string', Rule::in(Page::FRONTS)],
            'description' => ['nullable', 'string', 'max:1000'],
        ]);

        $page = $this->pageService->create($data);

        return ApiResponse::success($this->payload($page), 'Página creada', Response::HTTP_CREATED);
    }

    public function update(Request $request, Page $page): JsonResponse
    {
        abort_unless($request->user()?->hasPermission('roles.update'), Response::HTTP_FORBIDDEN);

        $data = $request->validate([
            'key' => ['sometimes', 'required', 'string', 'max:80', 'alpha_dash', Rule::unique('pages', 'key')->ignore($page->id)],
            'name' => ['sometimes', 'required', 'string', 'max:255'],
            'route_name' => ['nullable', 'string', 'max:120'],
            'icon' => ['nullable', 'string', 'max:100'],
            'sort_order' => ['sometimes', 'integer', 'min:0'],
            'is_active' => ['sometimes', 'boolean'],
            'front' => ['sometimes', 'required', 'string', Rule::in(Page::FRONTS)],
            'description' => ['nullable', 'string', 'max:1000'],
        ]);

        return ApiResponse::success($this->payload($this->pageService->update($page, $data)), 'Página actualizada');
    }

    public function destroy(Request $request, Page $page): JsonResponse
    {
        abort_unless($request->user()?->hasPermission('roles.update'), Response::HTTP_FORBIDDEN);
        $this->pageService->delete($page);

        return ApiResponse::success(null, 'Página eliminada');
    }

    /**
     * @return array<string, mixed>
     */
    private function payload(Page $page): array
    {
        $page->loadMissing(['permissions' => fn ($q) => $q->orderBy('sort_order')]);

        return [
            'id' => $page->id,
            'key' => $page->key,
            'name' => $page->name,
            'route_name' => $page->route_name,
            'icon' => $page->icon,
            'sort_order' => (int) $page->sort_order,
            'is_active' => (bool) $page->is_active,
            'front' => $page->front ?: Page::FRONT_PROJECT,
            'is_system' => $page->isSystem(),
            'description' => $page->description,
            'permissions' => $page->permissions->map(fn ($p) => [
                'id' => $p->id,
                'name' => $p->name,
                'display_name' => $p->display_name,
                'action' => $p->action,
            ])->values()->all(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function menuPayload(Page $page): array
    {
        return [
            'id' => $page->id,
            'key' => $page->key,
            'name' => $page->name,
            'route_name' => $page->route_name,
            'icon' => $page->icon,
            'sort_order' => (int) $page->sort_order,
            'front' => $page->front ?: Page::FRONT_PROJECT,
        ];
    }
}
