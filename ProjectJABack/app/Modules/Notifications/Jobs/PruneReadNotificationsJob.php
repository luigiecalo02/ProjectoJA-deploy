<?php

namespace App\Modules\Notifications\Jobs;

use App\Modules\Notifications\Services\ReadNotificationPruner;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

final class PruneReadNotificationsJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public function handle(ReadNotificationPruner $pruner): void
    {
        $pruner->prune();
    }
}
