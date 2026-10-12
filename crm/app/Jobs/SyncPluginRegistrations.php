<?php

namespace App\Jobs;

use App\Models\PluginConnection;
use App\Services\Plugin\RegistrationSync;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class SyncPluginRegistrations implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $timeout = 900;

    public int $tries = 1;

    public int $uniqueFor = 900;

    public function handle(RegistrationSync $sync): void
    {
        $connection = PluginConnection::current();

        if (! $connection->exists || ! $connection->isConfigured()) {
            return;
        }

        $result = $sync->run($connection);

        // A large backlog continues right away instead of waiting for the next scheduled run.
        if ($result['has_more']) {
            self::dispatch()->delay(now()->addSeconds(5));
        }
    }
}
