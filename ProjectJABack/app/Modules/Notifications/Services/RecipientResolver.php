<?php

namespace App\Modules\Notifications\Services;

use App\Models\User;
use Illuminate\Support\Collection;

final class RecipientResolver
{
    /**
     * @return Collection<int, User>
     */
    public function usersForPersona(int $personaId): Collection
    {
        if ($personaId <= 0) {
            return collect();
        }

        return User::query()
            ->where('persona_id', $personaId)
            ->where('is_active', true)
            ->get();
    }
}
