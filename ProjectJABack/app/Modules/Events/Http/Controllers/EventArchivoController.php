<?php

namespace App\Modules\Events\Http\Controllers;

use App\Modules\Events\Http\Requests\StoreEventArchivoRequest;
use App\Modules\Events\Models\Event;
use App\Modules\Events\Models\EventoArchivo;
use App\Modules\Events\Services\EventArchivoService;
use App\Modules\Shared\Http\Responses\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\Response;

final class EventArchivoController
{
    public function __construct(private readonly EventArchivoService $archivos) {}

    public function index(Request $request, Event $event): JsonResponse
    {
        abort_unless($request->user()->can('view', $event), Response::HTTP_FORBIDDEN);

        return ApiResponse::success($this->archivos->list($event));
    }

    public function store(StoreEventArchivoRequest $request, Event $event): JsonResponse
    {
        $data = $request->validated();
        $tipo = $data['tipo'] ?? null;
        $file = $request->file('archivo');

        if ($tipo === EventoArchivo::TIPO_YOUTUBE || (! $file && ! empty($data['url']))) {
            $archivo = $this->archivos->storeYoutube(
                $event,
                (string) ($data['url'] ?? ''),
                $data['titulo'] ?? null,
            );
        } elseif ($file) {
            $archivo = $this->archivos->storeFile(
                $event,
                $file,
                $request->user(),
                $data['titulo'] ?? null,
            );
        } else {
            throw ValidationException::withMessages([
                'archivo' => ['Adjunta un archivo o un enlace de YouTube.'],
            ]);
        }

        $archivo->load('file');

        return ApiResponse::success($this->archivos->toPayload($archivo), 'Material agregado', Response::HTTP_CREATED);
    }

    public function destroy(Request $request, Event $event, EventoArchivo $archivo): JsonResponse
    {
        abort_unless($request->user()->can('update', $event), Response::HTTP_FORBIDDEN);

        $this->archivos->delete($event, $archivo);

        return ApiResponse::success(null, 'Material eliminado');
    }
}
