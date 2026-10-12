<?php

namespace App\Console\Commands;

use App\Services\Surveys\SurveyDispatcher;
use Illuminate\Console\Command;

class SendSurveys extends Command
{
    protected $signature = 'surveys:send';

    protected $description = 'Opens the survey of every activity that just ended and queues it for the people who were there';

    public function handle(SurveyDispatcher $dispatcher): int
    {
        $queued = $dispatcher->tick();

        if ($queued) {
            $this->info("{$queued} encuestas en cola.");
        }

        return self::SUCCESS;
    }
}
