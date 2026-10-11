<?php

namespace App\Jobs;

use App\Models\ContactImport;
use App\Services\Contacts\ContactImporter;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

class ImportContacts implements ShouldQueue
{
    use Queueable;

    public int $timeout = 1800;

    public int $tries = 1;

    public function __construct(public ContactImport $import) {}

    public function handle(ContactImporter $importer): void
    {
        $importer->run($this->import);
    }

    public function failed(?Throwable $exception): void
    {
        $this->import->update([
            'status' => ContactImport::STATUS_FAILED,
            'errors' => [['row' => null, 'message' => $exception?->getMessage() ?? 'Error desconocido.']],
            'finished_at' => now(),
        ]);
    }
}
