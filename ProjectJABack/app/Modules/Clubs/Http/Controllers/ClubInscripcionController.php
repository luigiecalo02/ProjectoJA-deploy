<?php

namespace App\Modules\Clubs\Http\Controllers;

use App\Models\User;
use App\Modules\Auth\Services\AccountMailService;
use App\Modules\Clubs\Http\Requests\StoreClubInscripcionRequest;
use App\Modules\Clubs\Services\ClubInscripcionService;
use App\Modules\Organizations\Models\Organizacion;
use App\Modules\Settings\Services\PublicFormSettingsService;
use App\Modules\Shared\Http\Responses\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final class ClubInscripcionController
{
    public function __construct(
        private readonly ClubInscripcionService $inscripcion,
        private readonly AccountMailService $accountMail,
        private readonly PublicFormSettingsService $publicForm,
    ) {}

    public function options(): JsonResponse
    {
        return ApiResponse::success($this->publicForm->get());
    }

    public function clubes(Request $request): JsonResponse
    {
        $iglesiaId = $request->filled('iglesia_id') ? (int) $request->integer('iglesia_id') : null;

        return ApiResponse::success($this->inscripcion->catalogClubes($iglesiaId));
    }

    public function catalog(Request $request): JsonResponse
    {
        $tipo = (int) $request->integer('tipo_organizacion_id');
        $allowed = [
            Organizacion::TIPO_UNION,
            Organizacion::TIPO_ASOCIACION,
            Organizacion::TIPO_DISTRITO,
            Organizacion::TIPO_IGLESIA,
        ];
        if (! in_array($tipo, $allowed, true)) {
            return ApiResponse::error('Tipo de organización no válido.', Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $padreId = $request->filled('organizacion_padre_id')
            ? (int) $request->integer('organizacion_padre_id')
            : null;

        return ApiResponse::success($this->inscripcion->catalog($tipo, $padreId));
    }

    public function paises(): JsonResponse
    {
        return ApiResponse::success($this->inscripcion->paises());
    }

    public function departamentos(Request $request): JsonResponse
    {
        $paisId = $request->integer('pais_id') ?: null;

        return ApiResponse::success($this->inscripcion->departamentos($paisId));
    }

    public function ciudades(Request $request): JsonResponse
    {
        $departamentoId = $request->integer('departamento_id') ?: null;
        $departamentoIds = $request->input('departamento_ids');
        $departamentoIds = is_array($departamentoIds)
            ? array_map('intval', $departamentoIds)
            : (is_string($departamentoIds) ? array_filter(array_map('intval', explode(',', $departamentoIds))) : null);

        return ApiResponse::success($this->inscripcion->ciudades($departamentoId, $departamentoIds));
    }

    public function store(StoreClubInscripcionRequest $request): JsonResponse
    {
        $result = $this->inscripcion->register($request->validated());
        $email = $request->validated('usuario.email');
        $user = User::query()->where('email', $email)->first();
        if ($user) {
            $this->accountMail->trySendVerification($user);
        }

        return ApiResponse::success($result, 'Solicitud enviada. Revisa tu correo y confirma la cuenta. Si no lo encuentras, revisa la bandeja de spam.', Response::HTTP_CREATED);
    }
}
