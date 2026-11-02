<?php

namespace App\Modules\Notifications\Notifications;

use App\Modules\Events\Models\EventoParticipacionAbono;
use App\Modules\Notifications\Enums\ClubNotificationType;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Notification;
use NotificationChannels\WebPush\WebPushChannel;
use NotificationChannels\WebPush\WebPushMessage;

final class AbonoAprobadoNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public readonly EventoParticipacionAbono $abono,
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
            ->tag('abono-'.$this->abono->id)
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
     * @return array{tipo: string, title: string, body: string, icon: string, url: string, abono_id: int, evento_id: int, monto: float}
     */
    private function payload(): array
    {
        $this->abono->loadMissing('evento');
        $evento = $this->abono->evento?->name ?? 'tu actividad';
        $monto = number_format((float) $this->abono->monto, 0, ',', '.');

        return [
            'tipo' => ClubNotificationType::ABONO_APROBADO->value,
            'title' => 'Abono aprobado',
            'body' => sprintf('Se aprobó tu abono de $ %s en "%s".', $monto, $evento),
            'icon' => '/pwa-192x192.png',
            'url' => '/abonos',
            'abono_id' => (int) $this->abono->id,
            'evento_id' => (int) $this->abono->evento_id,
            'monto' => (float) $this->abono->monto,
        ];
    }
}
