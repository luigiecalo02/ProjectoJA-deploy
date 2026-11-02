<?php

namespace App\Modules\Notifications\Notifications;

use App\Modules\Events\Models\Event;
use App\Modules\Events\Models\EventoAsistencia;
use App\Modules\Notifications\Enums\ClubNotificationType;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Notification;
use NotificationChannels\WebPush\WebPushChannel;
use NotificationChannels\WebPush\WebPushMessage;

final class AsistenciaMarcadaNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public readonly Event $event,
        public readonly string $estado,
    ) {}

    /**
     * @return list<string|class-string>
     */
    public function via(object $notifiable): array
    {
        return ['database', WebPushChannel::class];
    }

    public function toWebPush(object $notifiable, mixed $notification): WebPushMessage
    {
        $payload = $this->payload();

        return (new WebPushMessage)
            ->title($payload['title'])
            ->body($payload['body'])
            ->icon($payload['icon'])
            ->data(['url' => $payload['url'], 'tipo' => $payload['tipo']])
            ->tag('asistencia-'.$this->event->id)
            ->options(['TTL' => 86400]);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        return $this->payload();
    }

    /**
     * @return array{tipo: string, title: string, body: string, icon: string, url: string, evento_id: int, estado: string}
     */
    private function payload(): array
    {
        return [
            'tipo' => ClubNotificationType::ASISTENCIA_MARCADA->value,
            'title' => 'Asistencia registrada',
            'body' => sprintf(
                'Tu asistencia en "%s" quedó como %s.',
                $this->event->name,
                $this->estadoLabel($this->estado),
            ),
            'icon' => '/pwa-192x192.png',
            'url' => '/eventos',
            'evento_id' => (int) $this->event->id,
            'estado' => $this->estado,
        ];
    }

    private function estadoLabel(string $estado): string
    {
        return match ($estado) {
            EventoAsistencia::ESTADO_PRESENTE => 'presente',
            EventoAsistencia::ESTADO_PUNTUAL => 'puntual',
            EventoAsistencia::ESTADO_TARDE => 'tarde',
            EventoAsistencia::ESTADO_JUSTIFICADO => 'justificado',
            EventoAsistencia::ESTADO_AUSENTE => 'ausente',
            default => $estado,
        };
    }
}
