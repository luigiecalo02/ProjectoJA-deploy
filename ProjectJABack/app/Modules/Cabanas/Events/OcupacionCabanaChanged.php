<?php

namespace App\Modules\Cabanas\Events;

use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

final class OcupacionCabanaChanged implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(
        public readonly int $eventoId,
        public readonly int $camaId,
        public readonly int $ocupacion,
        public readonly string $action,
    ) {}

    public function broadcastOn(): array
    {
        return [new PrivateChannel("events.{$this->eventoId}.cabanas")];
    }

    public function broadcastAs(): string
    {
        return 'cabanas.occupancy.changed';
    }

    public function broadcastWith(): array
    {
        return [
            'evento_id' => $this->eventoId,
            'cama_id' => $this->camaId,
            'ocupacion' => $this->ocupacion,
            'action' => $this->action,
        ];
    }
}
