<?php

namespace App\Modules\Auth\Http\Controllers;

use App\Modules\Auth\Http\Requests\CompleteParticipantRegistrationRequest;
use App\Modules\Auth\Http\Requests\StartParticipantRegistrationRequest;
use App\Modules\Auth\Http\Requests\VerifyParticipantRegistrationRequest;
use App\Modules\Auth\Services\ParticipantRegistrationService;
use App\Modules\Shared\Http\Responses\ApiResponse;
use Illuminate\Http\JsonResponse;

final class ParticipantRegistrationController
{
    public function __construct(
        private readonly ParticipantRegistrationService $registrationService,
    ) {}

    public function start(StartParticipantRegistrationRequest $request): JsonResponse
    {
        $result = $this->registrationService->start(
            $request->string('tipo_identificacion')->toString(),
            $request->string('identificacion')->toString(),
        );

        return ApiResponse::success(
            $result,
            'Si los datos son elegibles, recibirás un código por un canal registrado.'
        );
    }

    public function verify(VerifyParticipantRegistrationRequest $request): JsonResponse
    {
        return ApiResponse::success(
            $this->registrationService->verify(
                $request->string('challenge_id')->toString(),
                $request->string('otp')->toString(),
            ),
            'Código verificado'
        );
    }

    public function complete(CompleteParticipantRegistrationRequest $request): JsonResponse
    {
        $result = $this->registrationService->complete(
            $request->string('verification_token')->toString(),
            $request->validated(),
        );

        return ApiResponse::success(
            ['token' => $result['token']],
            'Registro completado',
            201
        );
    }
}
