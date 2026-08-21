<?php

namespace App\Modules\Events\Http\Controllers;

use App\Modules\Events\Models\Event;
use App\Modules\Events\Models\EventoEvidencia;
use App\Modules\Events\Services\EventParticipationService;
use App\Modules\Shared\Http\Responses\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\Response;

final class EventParticipationController
{
    public function __construct(private readonly EventParticipationService $service) {}

    public function enroll(Request $request, Event $event): JsonResponse
    {
        $inscripcion = $this->service->enrollClub($request->user(), $event);

        return ApiResponse::success([
            'id' => $inscripcion->id,
            'evento_id' => $inscripcion->evento_id,
            'tipo' => $inscripcion->tipo,
            'organizacion_id' => $inscripcion->organizacion_id,
            'estado' => $inscripcion->estado,
            'created_at' => $inscripcion->created_at?->toIso8601String(),
        ], 'Inscripción registrada', Response::HTTP_CREATED);
    }

    public function show(Request $request, Event $event): JsonResponse
    {
        $payload = $this->service->participationPayload($request->user(), $event);

        return ApiResponse::success($payload);
    }

    public function storeEvidencia(Request $request, Event $event): JsonResponse
    {
        $this->rejectIfRequestExceedsPhpLimit($request);

        $archivo = $request->file('archivo');
        if ($archivo && ! $archivo->isValid()) {
            throw ValidationException::withMessages([
                'archivo' => [$this->uploadErrorMessage($archivo)],
            ]);
        }

        $maxBytes = $this->iniBytes('upload_max_filesize');
        $maxKb = $maxBytes > 0 ? (int) floor($maxBytes / 1024) : 102400;
        $limitLabel = $this->uploadLimitLabel();

        $data = $request->validate([
            'tipo' => ['required', 'string', Rule::in(['link', 'pdf', 'imagen', 'audio', 'video'])],
            'titulo' => ['nullable', 'string', 'max:255'],
            'descripcion' => ['nullable', 'string'],
            'url' => ['nullable', 'string', 'max:2048'],
            'file_id' => ['nullable', 'integer', 'exists:files,id'],
            'archivo' => ['nullable', 'file', 'max:'.$maxKb],
            'estado' => ['sometimes', 'string', Rule::in([
                EventoEvidencia::ESTADO_BORRADOR,
                EventoEvidencia::ESTADO_ENVIADA,
            ])],
        ], [
            'archivo.uploaded' => "No se pudo subir el archivo. El servidor acepta hasta {$limitLabel}.",
            'archivo.max' => "El archivo no puede superar {$limitLabel}.",
            'archivo.file' => 'Debes adjuntar un archivo válido.',
        ]);

        $evidencia = $this->service->createEvidencia(
            $request->user(),
            $event,
            $data,
            $request->file('archivo'),
        );

        return ApiResponse::success(
            $this->service->evidenciaPayload($evidencia),
            'Evidencia registrada',
            Response::HTTP_CREATED
        );
    }

    private function rejectIfRequestExceedsPhpLimit(Request $request): void
    {
        $contentLength = (int) $request->server('CONTENT_LENGTH', 0);
        $postMax = $this->iniBytes('post_max_size');
        if ($contentLength > 0 && $postMax > 0 && $contentLength > $postMax) {
            throw ValidationException::withMessages([
                'archivo' => [$this->sizeLimitMessage()],
            ]);
        }
    }

    private function uploadErrorMessage(UploadedFile $archivo): string
    {
        return match ($archivo->getError()) {
            \UPLOAD_ERR_INI_SIZE, \UPLOAD_ERR_FORM_SIZE => $this->sizeLimitMessage(),
            \UPLOAD_ERR_PARTIAL => 'El archivo se subió incompleto. Intenta de nuevo.',
            \UPLOAD_ERR_NO_FILE => 'No se recibió el archivo.',
            \UPLOAD_ERR_NO_TMP_DIR => 'El servidor no tiene carpeta temporal para subir archivos.',
            \UPLOAD_ERR_CANT_WRITE => 'El servidor no pudo guardar el archivo.',
            default => 'No se pudo subir el archivo. Prueba con un archivo más pequeño o en otro formato (MP3/WAV).',
        };
    }

    private function sizeLimitMessage(): string
    {
        return "El archivo supera el tamaño máximo del servidor ({$this->uploadLimitLabel()}). Usa un archivo más pequeño.";
    }

    private function uploadLimitLabel(): string
    {
        $raw = trim((string) ini_get('upload_max_filesize'));

        return $raw !== '' ? $raw : '100M';
    }

    private function iniBytes(string $directive): int
    {
        $value = trim((string) ini_get($directive));
        if ($value === '' || $value === '0') {
            return 0;
        }

        $unit = strtolower(substr($value, -1));
        $number = (float) $value;

        return (int) match ($unit) {
            'g' => $number * 1024 * 1024 * 1024,
            'm' => $number * 1024 * 1024,
            'k' => $number * 1024,
            default => $number,
        };
    }

    public function destroyEvidencia(Request $request, EventoEvidencia $eventoEvidencia): JsonResponse
    {
        $this->service->deleteEvidencia($request->user(), $eventoEvidencia);

        return ApiResponse::success(null, 'Evidencia eliminada');
    }

    public function activityRoster(Request $request, Event $event): JsonResponse
    {
        return ApiResponse::success($this->service->activityRoster($request->user(), $event));
    }

    public function syncActivityRoster(Request $request, Event $event): JsonResponse
    {
        $data = $request->validate([
            'persona_ids' => ['required', 'array'],
            'persona_ids.*' => ['integer', 'exists:personas,id'],
        ]);

        return ApiResponse::success(
            $this->service->syncActivityRoster($request->user(), $event, $data['persona_ids']),
            'Integrantes inscritos',
        );
    }

    public function storeDirectorObservacion(Request $request, Event $event): JsonResponse
    {
        $data = $request->validate([
            'observaciones' => ['required', 'string', 'max:5000'],
        ]);

        $payload = $this->service->saveDirectorObservacion(
            $request->user(),
            $event,
            (string) $data['observaciones'],
        );

        return ApiResponse::success($payload, 'Observación guardada');
    }
}
