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
        $archivo = $request->file('archivo');
        if ($archivo && ! $archivo->isValid()) {
            throw ValidationException::withMessages([
                'archivo' => [$this->uploadErrorMessage($archivo)],
            ]);
        }

        $data = $request->validate([
            'tipo' => ['required', 'string', Rule::in(['link', 'pdf', 'imagen', 'audio', 'video'])],
            'titulo' => ['nullable', 'string', 'max:255'],
            'descripcion' => ['nullable', 'string'],
            'url' => ['nullable', 'string', 'max:2048'],
            'file_id' => ['nullable', 'integer', 'exists:files,id'],
            'archivo' => ['nullable', 'file', 'max:102400'],
            'estado' => ['sometimes', 'string', Rule::in([
                EventoEvidencia::ESTADO_BORRADOR,
                EventoEvidencia::ESTADO_ENVIADA,
            ])],
        ], [
            'archivo.uploaded' => 'No se pudo subir el archivo. Si es audio/video, prueba con uno de hasta 100 MB o reinicia Apache tras subir el límite de PHP.',
            'archivo.max' => 'El archivo no puede superar 100 MB.',
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

    private function uploadErrorMessage(UploadedFile $archivo): string
    {
        return match ($archivo->getError()) {
            \UPLOAD_ERR_INI_SIZE, \UPLOAD_ERR_FORM_SIZE => 'El archivo supera el tamaño máximo del servidor (100 MB). Usa un archivo más pequeño.',
            \UPLOAD_ERR_PARTIAL => 'El archivo se subió incompleto. Intenta de nuevo.',
            \UPLOAD_ERR_NO_FILE => 'No se recibió el archivo.',
            \UPLOAD_ERR_NO_TMP_DIR => 'El servidor no tiene carpeta temporal para subir archivos.',
            \UPLOAD_ERR_CANT_WRITE => 'El servidor no pudo guardar el archivo.',
            default => 'No se pudo subir el archivo. Prueba con un archivo más pequeño o en otro formato (MP3/WAV).',
        };
    }

    public function destroyEvidencia(Request $request, EventoEvidencia $eventoEvidencia): JsonResponse
    {
        $this->service->deleteEvidencia($request->user(), $eventoEvidencia);

        return ApiResponse::success(null, 'Evidencia eliminada');
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
