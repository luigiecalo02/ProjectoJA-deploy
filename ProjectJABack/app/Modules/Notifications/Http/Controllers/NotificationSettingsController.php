<?php

namespace App\Modules\Notifications\Http\Controllers;

use App\Modules\Notifications\Enums\ClubNotificationType;
use App\Modules\Notifications\Services\NotificationPreferenceService;
use App\Modules\Shared\Http\Responses\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

final class NotificationSettingsController
{
    public function __construct(private readonly NotificationPreferenceService $preferences) {}

    public function index(Request $request): JsonResponse
    {
        $orgId = $this->preferences->assertCanManage($request->user());

        return ApiResponse::success([
            'organizacion_id' => $orgId,
            'tipos' => $this->preferences->catalog($orgId),
        ]);
    }

    public function update(Request $request, string $tipo): JsonResponse
    {
        $parsed = ClubNotificationType::tryFrom($tipo);
        abort_unless($parsed !== null, 404, 'Tipo de notificación no válido.');

        $data = $request->validate([
            'activo' => ['required', 'boolean'],
            'tipo' => ['sometimes', Rule::in(array_column(ClubNotificationType::cases(), 'value'))],
        ]);

        $item = $this->preferences->update($request->user(), $parsed, (bool) $data['activo']);

        return ApiResponse::success($item, 'Preferencia actualizada');
    }
}
