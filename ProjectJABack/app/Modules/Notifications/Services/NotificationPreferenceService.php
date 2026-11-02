<?php

namespace App\Modules\Notifications\Services;

use App\Models\User;
use App\Modules\Notifications\Enums\ClubNotificationType;
use App\Modules\Notifications\Models\OrganizacionNotificacionTipo;
use App\Modules\Settings\Models\AppSetting;
use Symfony\Component\HttpFoundation\Response;

final class NotificationPreferenceService
{
    public function isEnabled(int $organizacionId, ClubNotificationType $tipo): bool
    {
        $row = OrganizacionNotificacionTipo::query()
            ->where('organizacion_id', $organizacionId)
            ->where('tipo', $tipo->value)
            ->first();

        return $row === null ? true : (bool) $row->activo;
    }

    /**
     * @return list<array{tipo: string, label: string, description: string, audience: string, activo: bool}>
     */
    public function catalog(int $organizacionId): array
    {
        $overrides = OrganizacionNotificacionTipo::query()
            ->where('organizacion_id', $organizacionId)
            ->get()
            ->keyBy('tipo');

        return array_map(function (ClubNotificationType $tipo) use ($overrides) {
            $row = $overrides->get($tipo->value);

            return [
                'tipo' => $tipo->value,
                'label' => $tipo->label(),
                'description' => $tipo->description(),
                'audience' => $tipo->audience(),
                'activo' => $row === null ? true : (bool) $row->activo,
            ];
        }, ClubNotificationType::catalog());
    }

    public function update(User $actor, ClubNotificationType $tipo, bool $activo): array
    {
        $orgId = $this->assertCanManage($actor);

        $row = OrganizacionNotificacionTipo::query()->updateOrCreate(
            [
                'organizacion_id' => $orgId,
                'tipo' => $tipo->value,
            ],
            ['activo' => $activo],
        );

        return [
            'tipo' => $tipo->value,
            'label' => $tipo->label(),
            'description' => $tipo->description(),
            'audience' => $tipo->audience(),
            'activo' => (bool) $row->activo,
        ];
    }

    public function assertCanManage(User $actor): int
    {
        $orgId = AppSetting::resolveOrganizacionId();
        abort_unless($orgId, Response::HTTP_FORBIDDEN, 'Debes tener una organización activa.');
        abort_unless(
            $actor->isClubDirectiva() || $actor->hasPermission('settings.update'),
            Response::HTTP_FORBIDDEN,
            'No puedes configurar las notificaciones del club.',
        );

        return $orgId;
    }

    public function resolveOrganizacionId(): ?int
    {
        return AppSetting::resolveOrganizacionId();
    }
}
