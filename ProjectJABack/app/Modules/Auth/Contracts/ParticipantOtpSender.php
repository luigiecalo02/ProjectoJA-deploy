<?php

namespace App\Modules\Auth\Contracts;

use App\Modules\Clubs\Models\Persona;

interface ParticipantOtpSender
{
    public function send(Persona $persona, string $otp): bool;
}
