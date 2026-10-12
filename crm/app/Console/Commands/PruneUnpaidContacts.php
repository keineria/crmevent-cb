<?php

namespace App\Console\Commands;

use App\Models\Contact;
use App\Models\Registration;
use Illuminate\Console\Command;

/**
 * Removes contacts the plugin sync created for checkouts that were never paid (before unpaid orders stopped
 * creating contacts). Anyone with another trace in the CRM (fair history, a paid order, a subscription, consent
 * or an unsubscribe) is kept. Their participant rows stay, unlinked.
 */
class PruneUnpaidContacts extends Command
{
    protected $signature = 'crm:prune-unpaid-contacts {--dry-run : Solo cuenta, no borra}';

    protected $description = 'Borra los contactos creados por intentos de compra sin pagar';

    public function handle(): int
    {
        $query = Contact::query()
            ->where('source', Contact::SOURCE_PLUGIN)
            ->where('is_attendee', false)
            ->where('is_interested', false)
            ->where('is_subscriber', false)
            ->where('marketing_consent', false)
            ->whereNull('subscription_requested_at')
            ->whereNull('unsubscribed_at')
            ->whereDoesntHave('editions')
            ->whereDoesntHave('registrationParticipants.registration', fn ($q) => $q->whereIn('status', Registration::PAID_STATUSES));

        $ids = $query->pluck('id');
        $count = $ids->count();

        if ($this->option('dry-run')) {
            $this->info("Se borrarían {$count} contactos de intentos sin pagar.");

            return self::SUCCESS;
        }

        // Participants and buyers point at contacts with nullOnDelete, so they simply lose the link.
        $ids->chunk(500)->each(fn ($chunk) => Contact::whereIn('id', $chunk)->delete());
        $this->info("Se borraron {$count} contactos de intentos sin pagar.");

        return self::SUCCESS;
    }
}
