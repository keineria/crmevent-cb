<?php

namespace App\Console\Commands;

use App\Services\Contacts\ContactInsights;
use Illuminate\Console\Command;

class RefreshContactInsights extends Command
{
    protected $signature = 'contacts:refresh-insights';

    protected $description = 'Recalcula el producto preferido y las estrellas de cada contacto';

    public function handle(ContactInsights $insights): int
    {
        $changed = $insights->refreshAll();
        $this->info("Se actualizaron {$changed} contactos.");

        return self::SUCCESS;
    }
}
