<?php

namespace App\Modules\Notifications\Notifications;

use App\Modules\Events\Models\EventoParticipacionAbono;
use App\Modules\Notifications\Enums\ClubNotificationType;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Notification;
use NotificationChannels\WebPush\WebPushChannel;
use NotificationChannels\WebPush\WebPushMessage;

final class AbonoComprobanteSubidoNotification extends Notification implements ShouldQueue
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
            ->tag('abono-comprobante-'.$this->abono->id)
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
     * @return array{tipo: string, title: string, body: string, icon: string, url: string, abono_id: int, evento_id: int, persona_id: int, monto: float}
     */
    private function payload(): array
    {
        $this->abono->loadMissing(['evento', 'persona']);
        $persona = $this->abono->persona?->full_name ?: 'Un integrante';
        $monto = (float) $this->abono->monto;
        $montoTxt = number_format($monto, 0, ',', '.');
        if ($this->abono->isLibre() && ! $this->abono->evento_id) {
            $body = $monto > 0
                ? sprintf('%s envió un abono libre de $ %s.', $persona, $montoTxt)
                : sprintf('%s envió un abono libre.', $persona);
        } else {
            $evento = $this->abono->evento?->name ?? 'una actividad';
            $body = $monto > 0
                ? sprintf('%s subió un comprobante de $ %s en "%s".', $persona, $montoTxt, $evento)
                : sprintf('%s subió un comprobante en "%s".', $persona, $evento);
        }

        return [
            'tipo' => ClubNotificationType::ABONO_COMPROBANTE_SUBIDO->value,
            'title' => 'Comprobante por revisar',
            'body' => $body,
            'icon' => '/pwa-192x192.png',
            'url' => '/abonos',
            'abono_id' => (int) $this->abono->id,
            'evento_id' => $this->abono->evento_id !== null ? (int) $this->abono->evento_id : null,
            'persona_id' => (int) $this->abono->persona_id,
            'monto' => $monto,
        ];
    }
}
