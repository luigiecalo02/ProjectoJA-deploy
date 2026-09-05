<?php

namespace App\Modules\Events\Http\Controllers;

use App\Modules\Events\Models\Event;
use App\Modules\Events\Models\Icono;
use App\Modules\Events\Services\IconoCatalogService;
use App\Modules\Shared\Http\Responses\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final class IconoController
{
    public function __construct(private readonly IconoCatalogService $service) {}

    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        abort_unless(
            $user->can('viewAny', Event::class) || $user->hasPermission('settings.view'),
            Response::HTTP_FORBIDDEN,
        );

        $items = collect($this->service->list(
            $request->boolean('todos'),
            $request->string('categoria')->toString() ?: null,
            $request->string('q')->toString() ?: null,
        ))->map(fn (Icono $icono) => $this->payload($icono))->values()->all();

        return ApiResponse::success($items);
    }

    public function store(Request $request): JsonResponse
    {
        $user = $request->user();
        abort_unless(
            $user->can('create', Event::class) || $user->hasPermission('settings.update'),
            Response::HTTP_FORBIDDEN,
        );

        $data = $request->validate($this->rules(true));
        $icono = $this->service->create($data, $request->file('archivo'));

        return ApiResponse::success($this->payload($icono), 'Icono creado', Response::HTTP_CREATED);
    }

    public function update(Request $request, Icono $icono): JsonResponse
    {
        $user = $request->user();
        abort_unless(
            $user->hasPermission('events.update') || $user->hasPermission('settings.update'),
            Response::HTTP_FORBIDDEN,
        );

        $data = $request->validate($this->rules(false));
        $icono = $this->service->update($icono, $data, $request->file('archivo'));

        return ApiResponse::success($this->payload($icono), 'Icono actualizado');
    }

    public function destroy(Request $request, Icono $icono): JsonResponse
    {
        $user = $request->user();
        abort_unless(
            $user->hasPermission('events.delete') || $user->hasPermission('settings.update'),
            Response::HTTP_FORBIDDEN,
        );

        $this->service->delete($icono);

        return ApiResponse::success(null, 'Icono eliminado');
    }

    /**
     * @return array<string, mixed>
     */
    private function rules(bool $creating): array
    {
        return [
            'nombre' => [$creating ? 'required' : 'sometimes', 'required', 'string', 'max:120'],
            'slug' => ['nullable', 'string', 'max:140'],
            'categoria' => ['nullable', 'string', 'max:40'],
            'etiquetas' => ['nullable'],
            'tipo' => ['nullable', 'string', 'in:prime,imagen'],
            'valor' => [$creating ? 'required_without:archivo' : 'nullable', 'string', 'max:255'],
            'orden' => ['nullable', 'integer', 'min:0', 'max:65535'],
            'estado' => ['sometimes', 'boolean'],
            'archivo' => ['nullable', 'file', 'mimes:jpg,jpeg,png,webp,gif,svg', 'max:4096'],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function payload(Icono $icono): array
    {
        $esImagen = $icono->tipo === 'imagen';

        return [
            'id' => $icono->id,
            'nombre' => $icono->nombre,
            'slug' => $icono->slug,
            'categoria' => $icono->categoria,
            'etiquetas' => array_values($icono->etiquetas ?? []),
            'tipo' => $icono->tipo,
            'valor' => $icono->valor,
            'url' => $esImagen ? $this->publicUrl($icono->valor) : null,
            'orden' => (int) $icono->orden,
            'estado' => (bool) $icono->estado,
            'es_sistema' => (bool) $icono->es_sistema,
        ];
    }

    private function publicUrl(string $path): ?string
    {
        if ($path === '') {
            return null;
        }
        if (str_starts_with($path, 'http://') || str_starts_with($path, 'https://')) {
            return $path;
        }

        return url('storage/'.ltrim($path, '/'));
    }
}
