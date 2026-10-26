<?php

namespace App\Jobs;

use App\Models\PluginConnection;
use App\Services\Plugin\RegistrationSync;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class ReconcilePluginOrders implements ShouldBeUnique, ShouldQueue
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

        $sync->reconcile($connection);
    }
}
