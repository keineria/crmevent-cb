<?php

namespace App\Services\Contacts;

use App\Models\CampaignRecipient;
use App\Models\Contact;
use App\Models\ContactEdition;
use App\Models\Registration;
use App\Models\RegistrationActivity;
use Illuminate\Support\Collection;

/**
 * Values the CRM works out for each contact instead of asking for them:
 *
 * - Preferred products: what they bought, from the plugin (paid orders) and from the fair masters (the
 *   CONFERENCIAS / SALIDAS / TALLERES / STAND columns, or the plan name). Left alone once staff set it by hand.
 * - Stars (1 to 5): how much they engage with campaigns. Opening counts 70 % and clicking 30 %, over the campaigns
 *   they were sent; someone who opens and clicks every email gets 5, someone who never opens gets 1. Without any
 *   campaign sent there are no stars yet.
 */
class ContactInsights
{
    private const PLUGIN_TYPES = ['conference' => 'conferencias', 'birding' => 'salidas', 'workshop' => 'talleres', 'other' => 'otras'];

    /** Fair-master columns that say "SI" when the person bought that product. */
    private const MASTER_COLUMNS = ['conferencias' => 'conferencias', 'salidas' => 'salidas', 'talleres' => 'talleres', 'stand' => 'stand'];

    /** Words in a plan name ("Plan Conferencias + 2 Salidas de Pajareo") and the product they point to. */
    private const PLAN_WORDS = ['conferencia' => 'conferencias', 'salida' => 'salidas', 'taller' => 'talleres', 'stand' => 'stand', 'networking' => 'otras'];

    private const PLAN_COLUMNS = ['registro', 'plan'];

    public function refresh(Contact $contact): void
    {
        $contact->load(['editions', 'registrationParticipants.registration', 'registrationParticipants.activities']);
        $engagement = $this->engagement(collect([$contact->id]))->get($contact->id);

        $this->apply($contact, $engagement);
    }

    /**
     * Recomputes every contact; saves only the ones that changed. Returns how many changed.
     */
    public function refreshAll(): int
    {
        $changed = 0;

        Contact::query()
            ->with(['editions', 'registrationParticipants.registration', 'registrationParticipants.activities'])
            ->chunkById(300, function (Collection $contacts) use (&$changed) {
                $engagement = $this->engagement($contacts->pluck('id'));
                foreach ($contacts as $contact) {
                    $changed += $this->apply($contact, $engagement->get($contact->id)) ? 1 : 0;
                }
            });

        return $changed;
    }

    /**
     * Products a contact bought, in the order of Contact::PRODUCTS.
     *
     * @return list<string>
     */
    public function products(Contact $contact): array
    {
        $found = [];

        foreach ($contact->editions as $edition) {
            if ($edition->role !== ContactEdition::ROLE_ATTENDEE) {
                continue;
            }
            foreach ($edition->details ?? [] as $column => $value) {
                $column = ImportFields::normalize((string) $column);
                $value = ImportFields::normalize((string) $value);

                if (isset(self::MASTER_COLUMNS[$column]) && in_array($value, ['si', 'x', '1', 'yes'], true)) {
                    $found[self::MASTER_COLUMNS[$column]] = true;
                }
                if (in_array($column, self::PLAN_COLUMNS, true)) {
                    foreach (self::PLAN_WORDS as $word => $product) {
                        if (str_contains($value, $word)) {
                            $found[$product] = true;
                        }
                    }
                }
            }
        }

        foreach ($contact->registrationParticipants as $participant) {
            if (! in_array($participant->registration?->status, Registration::PAID_STATUSES, true)) {
                continue;
            }
            foreach ($participant->activities as $activity) {
                if (in_array($activity->status, RegistrationActivity::ACTIVE_STATUSES, true) && isset(self::PLUGIN_TYPES[$activity->type])) {
                    $found[self::PLUGIN_TYPES[$activity->type]] = true;
                }
            }
        }

        return self::sortProducts(array_keys($found));
    }

    public static function stars(int $sent, int $opened, int $clicked): ?int
    {
        if ($sent === 0) {
            return null;
        }

        $score = 0.7 * min(1, $opened / $sent) + 0.3 * min(1, $clicked / $sent);

        return max(1, min(5, 1 + (int) round($score * 4)));
    }

    /**
     * Turns free text ("Conferencias y Salidas", "talleres; stand") into product keys.
     *
     * @return list<string>
     */
    public static function parseProducts(string $text): array
    {
        $found = [];
        $normalized = ImportFields::normalize($text);
        $words = [...self::PLAN_WORDS, 'otra' => 'otras', 'actividad' => 'otras'];

        foreach ($words as $word => $product) {
            if (str_contains($normalized, $word)) {
                $found[$product] = true;
            }
        }

        return self::sortProducts(array_keys($found));
    }

    /**
     * @param  list<string>  $products
     * @return list<string>
     */
    public static function sortProducts(array $products): array
    {
        return array_values(array_intersect(array_keys(Contact::PRODUCTS), $products));
    }

    /**
     * @param  array{sent: int, opened: int, clicked: int}|null  $engagement
     */
    private function apply(Contact $contact, ?array $engagement): bool
    {
        $engagement ??= ['sent' => 0, 'opened' => 0, 'clicked' => 0];

        $contact->forceFill([
            'emails_sent' => min(65535, $engagement['sent']),
            'emails_opened' => min(65535, $engagement['opened']),
            'emails_clicked' => min(65535, $engagement['clicked']),
            'engagement_stars' => self::stars($engagement['sent'], $engagement['opened'], $engagement['clicked']),
        ]);

        if (! $contact->preferred_products_manual) {
            $products = $this->products($contact);
            $contact->preferred_products = $products ?: null;
        }

        if (! $contact->isDirty()) {
            return false;
        }

        // Derived values only: keep updated_at as the last time someone changed the person's data.
        $contact->timestamps = false;
        $contact->save();
        $contact->timestamps = true;

        return true;
    }

    /**
     * Campaigns sent, opened (a click counts as an open) and clicked, per contact.
     *
     * @param  Collection<int, int>  $ids
     * @return Collection<int, array{sent: int, opened: int, clicked: int}>
     */
    private function engagement(Collection $ids): Collection
    {
        return CampaignRecipient::query()
            ->whereIn('contact_id', $ids)
            ->where('status', CampaignRecipient::SENT)
            ->groupBy('contact_id')
            ->select('contact_id')
            ->selectRaw('count(*) as sent')
            ->selectRaw('sum(case when opened_at is not null or clicked_at is not null then 1 else 0 end) as opened')
            ->selectRaw('sum(case when clicked_at is not null then 1 else 0 end) as clicked')
            ->get()
            ->mapWithKeys(fn ($r) => [(int) $r->contact_id => ['sent' => (int) $r->sent, 'opened' => (int) $r->opened, 'clicked' => (int) $r->clicked]]);
    }
}
