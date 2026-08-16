<?php

return [
    'otp_ttl_minutes' => (int) env('PARTICIPANT_OTP_TTL', 10),
    'verification_ttl_minutes' => (int) env('PARTICIPANT_VERIFICATION_TTL', 15),
    'max_otp_attempts' => (int) env('PARTICIPANT_OTP_MAX_ATTEMPTS', 5),
    'sms' => [
        'webhook' => env('PARTICIPANT_SMS_WEBHOOK'),
        'token' => env('PARTICIPANT_SMS_TOKEN'),
    ],
];
