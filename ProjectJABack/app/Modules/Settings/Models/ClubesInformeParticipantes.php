<?php

namespace App\Modules\Settings\Models;

use App\Models\User;
use App\Modules\Organizations\Models\Organizacion;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ClubesInformeParticipantes extends Model
{
    protected $table = 'clubes_informes_participantes';

    protected $fillable = [
        'organizacion_id',
        'desde',
        'hasta',
        'token_hash',
        'token',
        'expires_at',
        'revoked_at',
        'created_by',
    ];

    protected $hidden = [
        'token',
        'token_hash',
    ];

    protected function casts(): array
    {
        return [
            'desde' => 'date',
            'hasta' => 'date',
            'expires_at' => 'datetime',
            'revoked_at' => 'datetime',
        ];
    }

    public function organizacion(): BelongsTo
    {
        return $this->belongsTo(Organizacion::class, 'organizacion_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function isActive(): bool
    {
        if ($this->revoked_at !== null) {
            return false;
        }
        if ($this->expires_at !== null && $this->expires_at->isPast()) {
            return false;
        }

        return true;
    }
}
