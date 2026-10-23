<?php

namespace App\Console\Commands;

use App\Models\StandWorkbook;
use App\Services\Stands\GoogleStandSheet;
use Illuminate\Console\Command;

class SyncStandSheets extends Command
{
    protected $signature = 'stands:sync';

    protected $description = 'Reads again every Stand comercial book connected to a Google Sheet, so changes made in Drive show up';

    public function handle(GoogleStandSheet $sheets): int
    {
        StandWorkbook::query()->where('source', StandWorkbook::SOURCE_GOOGLE)->each(function (StandWorkbook $workbook) use ($sheets) {
            if (! $sheets->pull($workbook)) {
                $this->warn("{$workbook->name}: {$workbook->sync_error}");
            }
        });

        return self::SUCCESS;
    }
}
