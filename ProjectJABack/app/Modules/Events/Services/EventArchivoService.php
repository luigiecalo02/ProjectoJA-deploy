<?php

namespace App\Modules\Events\Services;

use App\Models\User;
use App\Modules\Events\Models\Event;
use App\Modules\Events\Models\EventoArchivo;
use App\Modules\Shared\Models\StoredFile;
use App\Modules\Shared\Services\ImageOptimizer;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

final class EventArchivoService
{
    public const MAX_VIDEO_KB = 51200;

    public function __construct(private readonly ImageOptimizer $imageOptimizer) {}

    /**
     * @return list<array<string, mixed>>
     */
    public function list(Event $event): array
    {
        $items = $event->relationLoaded('archivos')
            ? $event->archivos
            : $event->archivos()->with('file')->orderBy('orden')->orderBy('id')->get();

        if ($items instanceof Collection && ! $event->relationLoaded('archivos')) {
            $items->loadMissing('file');
        } elseif ($event->relationLoaded('archivos')) {
            $event->loadMissing('archivos.file');
            $items = $event->archivos->sortBy([
                ['orden', 'asc'],
                ['id', 'asc'],
            ])->values();
        }

        return $items->map(fn (EventoArchivo $archivo) => $this->toPayload($archivo))->values()->all();
    }

    public function storeFile(Event $event, UploadedFile $file, User $actor, ?string $titulo = null): EventoArchivo
    {
        $tipo = $this->tipoFromUpload($file);
        $stored = $this->imageOptimizer->store($file, "events/{$event->id}/archivos", $tipo);
        $url = url('storage/'.$stored->path);

        $storedFile = StoredFile::query()->create([
            'name' => $file->getClientOriginalName(),
            'path' => $stored->path,
            'size' => $stored->size,
            'mime_type' => $stored->mime,
            'hash' => $stored->hash,
            'uploaded_by' => $actor->id,
        ]);

        return EventoArchivo::query()->create([
            'evento_id' => $event->id,
            'file_id' => $storedFile->id,
            'url' => $url,
            'titulo' => $this->resolveTitulo($titulo, $file->getClientOriginalName()),
            'tipo' => $tipo,
            'orden' => $this->nextOrden($event),
        ]);
    }

    public function storeYoutube(Event $event, string $rawUrl, ?string $titulo = null): EventoArchivo
    {
        $url = $this->normalizeYoutubeUrl($rawUrl);

        return EventoArchivo::query()->create([
            'evento_id' => $event->id,
            'file_id' => null,
            'url' => $url,
            'titulo' => $this->resolveTitulo($titulo, 'YouTube'),
            'tipo' => EventoArchivo::TIPO_YOUTUBE,
            'orden' => $this->nextOrden($event),
        ]);
    }

    public function delete(Event $event, EventoArchivo $archivo): void
    {
        if ((int) $archivo->evento_id !== (int) $event->id) {
            throw ValidationException::withMessages([
                'archivo' => ['El archivo no pertenece a este evento.'],
            ]);
        }

        $file = $archivo->file;
        $archivo->delete();

        if ($file?->path) {
            Storage::disk('public')->delete($file->path);
            $file->delete();
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function toPayload(EventoArchivo $archivo): array
    {
        $file = $archivo->relationLoaded('file') ? $archivo->file : $archivo->file()->first();

        return [
            'id' => (int) $archivo->id,
            'evento_id' => (int) $archivo->evento_id,
            'tipo' => $archivo->tipo,
            'titulo' => $archivo->titulo,
            'url' => $archivo->url ?: ($file?->path ? url('storage/'.$file->path) : null),
            'name' => $file?->name ?? $archivo->titulo,
            'size' => $file?->size !== null ? (int) $file->size : null,
            'mime_type' => $file?->mime_type,
            'orden' => (int) $archivo->orden,
        ];
    }

    public function normalizeYoutubeUrl(string $raw): string
    {
        $trimmed = trim($raw);
        if ($trimmed === '') {
            throw ValidationException::withMessages([
                'url' => ['Indica un enlace de YouTube.'],
            ]);
        }

        if (! preg_match('#^[a-z][a-z0-9+.-]*:#i', $trimmed)) {
            $trimmed = str_starts_with($trimmed, '//') ? 'https:'.$trimmed : 'https://'.$trimmed;
        }

        $parts = parse_url($trimmed);
        $host = strtolower((string) ($parts['host'] ?? ''));
        $host = preg_replace('/^www\./', '', $host) ?? $host;
        $path = (string) ($parts['path'] ?? '');
        $query = [];
        parse_str((string) ($parts['query'] ?? ''), $query);

        $id = null;
        if ($host === 'youtu.be') {
            $id = explode('/', trim($path, '/'))[0] ?? null;
        } elseif (in_array($host, ['youtube.com', 'm.youtube.com', 'music.youtube.com', 'youtube-nocookie.com'], true)) {
            if (
                str_starts_with($path, '/embed/')
                || str_starts_with($path, '/shorts/')
                || str_starts_with($path, '/live/')
            ) {
                $id = explode('/', trim($path, '/'))[1] ?? null;
            } else {
                $id = $query['v'] ?? null;
            }
        }

        $id = is_string($id) ? preg_replace('/[^a-zA-Z0-9_-]/', '', $id) : null;
        if (! is_string($id) || $id === '') {
            throw ValidationException::withMessages([
                'url' => ['El enlace no es un video de YouTube válido.'],
            ]);
        }

        return 'https://www.youtube.com/watch?v='.$id;
    }

    private function tipoFromUpload(UploadedFile $file): string
    {
        $ext = strtolower((string) $file->getClientOriginalExtension());
        $mime = (string) $file->getMimeType();

        if ($ext === 'pdf' || $mime === 'application/pdf') {
            return EventoArchivo::TIPO_PDF;
        }

        if (str_starts_with($mime, 'image/') || in_array($ext, ['jpg', 'jpeg', 'png', 'webp', 'gif'], true)) {
            return EventoArchivo::TIPO_IMAGEN;
        }

        if (str_starts_with($mime, 'video/') || in_array($ext, ['mp4', 'webm', 'mov', 'mkv'], true)) {
            return EventoArchivo::TIPO_VIDEO;
        }

        throw ValidationException::withMessages([
            'archivo' => ['Solo se permiten PDF, imágenes, video o un enlace de YouTube.'],
        ]);
    }

    private function nextOrden(Event $event): int
    {
        return ((int) $event->archivos()->max('orden')) + 1;
    }

    private function resolveTitulo(?string $titulo, string $fallback): string
    {
        $clean = trim((string) $titulo);

        return $clean !== '' ? $clean : $fallback;
    }
}
