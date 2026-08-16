<?php

namespace App\Modules\Organizations\Events;

use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Notifica cambios de organizaciones a clientes suscritos al canal privado.
 *
 * @phpstan-type Action 'created'|'updated'|'deleted'
 */
final class OrganizacionChanged implements ShouldBroadcastNow
{
    use Dispatchable;
    use InteractsWithSockets;
    use SerializesModels;

    /**
     * @param  Action  $action
     */
    public function __construct(
        public readonly string $action,
        public readonly int $organizacionId,
    ) {}

    /**
     * @return array<int, PrivateChannel>
     */
    public function broadcastOn(): array
    {
        return [
            new PrivateChannel('organizations'),
        ];
    }

    public function broadcastAs(): string
    {
        return 'organizacion.changed';
    }

    /**
     * @return array{action: string, organizacion_id: int}
     */
    public function broadcastWith(): array
    {
        return [
            'action' => $this->action,
            'organizacion_id' => $this->organizacionId,
        ];
    }
}
