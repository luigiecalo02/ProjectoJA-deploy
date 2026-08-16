<?php

namespace App\Console\Commands;

use App\Modules\Users\Services\UserService;
use Illuminate\Console\Command;

class LinkOrphanUsersToPersonas extends Command
{
    protected $signature = 'users:link-orphan-personas';

    protected $description = 'Crea personas stub y las vincula en users.persona_id';

    public function handle(UserService $userService): int
    {
        $created = $userService->linkOrphanUsers();
        $this->info("Personas creadas/vinculadas: {$created}");

        return self::SUCCESS;
    }
}
