<?php

namespace App\Modules\Auth\Services;

use App\Modules\Auth\Contracts\ParticipantOtpSender;
use App\Modules\Auth\Notifications\ParticipantRegistrationOtpNotification;
use App\Modules\Clubs\Models\Persona;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;

final class LaravelParticipantOtpSender implements ParticipantOtpSender
{
    public function send(Persona $persona, string $otp): bool
    {
        if (filled($persona->correo)) {
            Notification::route('mail', $persona->correo)
                ->notify(new ParticipantRegistrationOtpNotification($otp));

            return true;
        }

        $webhook = config('participant-registration.sms.webhook');
        if (! filled($persona->telefono) || ! filled($webhook)) {
            return false;
        }

        $response = Http::asJson()
            ->withToken((string) config('participant-registration.sms.token'))
            ->timeout(5)
            ->post($webhook, [
                'to' => $persona->telefono,
                'message' => "Tu código de registro ProjectJA es {$otp}. Vence en 10 minutos.",
            ]);

        return $response->successful();
    }
}
