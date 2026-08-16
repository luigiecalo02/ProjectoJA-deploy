<?php

namespace App\Modules\Auth\Models;

use App\Modules\Clubs\Models\Persona;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class ParticipantRegistrationChallenge extends Model
{
    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
        'id',
        'persona_id',
        'identifier_hash',
        'otp_hash',
        'attempts',
        'expires_at',
        'consumed_at',
        'verification_token_hash',
        'verification_expires_at',
    ];

    protected $hidden = [
        'otp_hash',
        'identifier_hash',
        'verification_token_hash',
    ];

    protected function casts(): array
    {
        return [
            'expires_at' => 'datetime',
            'consumed_at' => 'datetime',
            'verification_expires_at' => 'datetime',
        ];
    }

    public function persona(): BelongsTo
    {
        return $this->belongsTo(Persona::class, 'persona_id');
    }
}
