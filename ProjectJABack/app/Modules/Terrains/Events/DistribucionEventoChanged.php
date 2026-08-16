<?php

namespace App\Modules\Terrains\Events;

use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

final class DistribucionEventoChanged implements ShouldBroadcastNow
{
    use Dispatchable;
    use InteractsWithSockets;
    use SerializesModels;

    public function __construct(
        public readonly int $eventoId,
        public readonly int $loteId,
        public readonly string $action,
    ) {}

    public function broadcastOn(): array
    {
        return [
            new PrivateChannel("events.{$this->eventoId}.distribution"),
        ];
    }

    public function broadcastAs(): string
    {
        return 'distribution.changed';
    }

    public function broadcastWith(): array
    {
        return [
            'evento_id' => $this->eventoId,
            'lote_id' => $this->loteId,
            'action' => $this->action,
        ];
    }
}
