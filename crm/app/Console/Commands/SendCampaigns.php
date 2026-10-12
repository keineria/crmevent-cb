<?php

namespace App\Console\Commands;

use App\Services\Campaigns\CampaignDispatcher;
use Illuminate\Console\Command;

class SendCampaigns extends Command
{
    protected $signature = 'campaigns:send';

    protected $description = 'Starts scheduled campaigns and queues the next batch of emails within the mailbox limits';

    public function handle(CampaignDispatcher $dispatcher): int
    {
        $queued = $dispatcher->tick();

        if ($queued) {
            $this->info("{$queued} correos en cola.");
        }

        return self::SUCCESS;
    }
}
