<?php

namespace App\Services\Plugin;

use App\Models\Contact;
use App\Models\ContactEdition;
use App\Models\EventActivity;
use App\Models\PluginConnection;
use App\Models\Registration;
use App\Models\RegistrationActivity;
use App\Models\RegistrationParticipant;
use App\Services\Contacts\ContactInsights;
use App\Services\Contacts\ContactMatcher;
use Illuminate\Support\Arr;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Throwable;

/**
 * Pulls activities and registrations from the plugin into the CRM. The plugin stays the source of truth:
 * this only mirrors what it reports, upserting by plugin ids so running it twice changes nothing.
 */
class RegistrationSync
{
    /** Safety limit per run; the next run continues from the saved cursor. */
    private const MAX_PAGES = 40;

    public function __construct(private ContactMatcher $matcher, private ContactInsights $insights) {}

    /**
     * @return array{activities: int, orders: int, participants: int, new_contacts: int, has_more: bool}
     */
    public function run(PluginConnection $connection, ?EvrClient $client = null): array
    {
        $client ??= EvrClient::for($connection);
        $result = ['activities' => 0, 'orders' => 0, 'participants' => 0, 'new_contacts' => 0, 'has_more' => false];

        try {
            foreach ($client->activities() as $activity) {
                $this->upsertActivity($activity);
                $result['activities']++;
            }

            $cursor = ['updated_at' => $connection->cursor_updated, 'order_id' => (int) $connection->cursor_order];
            $createdAfter = $connection->created_after?->toDateString();

            for ($page = 0; $page < self::MAX_PAGES; $page++) {
                $batch = $client->orders($cursor, $createdAfter);

                foreach ($batch['data'] as $order) {
                    DB::transaction(function () use ($order, &$result) {
                        $this->upsertOrder($order, $result);
                    });
                    $result['orders']++;
                }

                if ($batch['next_cursor']) {
                    $cursor = ['updated_at' => $batch['next_cursor']['updated_at'], 'order_id' => (int) $batch['next_cursor']['order_id']];
                    $connection->forceFill(['cursor_updated' => $cursor['updated_at'], 'cursor_order' => $cursor['order_id']])->save();
                }

                $result['has_more'] = $batch['has_more'];
                if (! $batch['has_more']) {
                    break;
                }
            }

            $connection->forceFill([
                'last_synced_at' => now(),
                'last_status' => 'ok',
                'last_error' => null,
                'last_result' => $result,
            ])->save();
        } catch (Throwable $e) {
            $connection->forceFill([
                'last_synced_at' => now(),
                'last_status' => 'error',
                'last_error' => Str::limit($e->getMessage(), 1000),
                'last_result' => $result,
            ])->save();

            throw $e;
        }

        return $result;
    }

    /**
     * Mirrors orders the plugin just returned after a change made from the CRM, without waiting for the next sync.
     *
     * @param  list<array<string, mixed>>  $orders  Items in the shape of GET /orders.
     */
    public function applyOrders(array $orders): void
    {
        $result = ['activities' => 0, 'orders' => 0, 'participants' => 0, 'new_contacts' => 0, 'has_more' => false];

        foreach ($orders as $order) {
            DB::transaction(fn () => $this->upsertOrder($order, $result));
        }
    }

    /**
     * @param  array<string, mixed>  $a
     */
    private function upsertActivity(array $a): void
    {
        EventActivity::updateOrCreate(['evr_id' => (int) $a['id']], [
            'type' => (string) ($a['type'] ?? ''),
            'name' => Str::limit((string) ($a['name'] ?? ''), 250, ''),
            'destination' => $a['destination'] ?? null,
            'event_date' => $a['event_date'] ?? null,
            'start_time' => $a['start_time'] ?? null,
            'end_time' => $a['end_time'] ?? null,
            'price' => (float) ($a['price'] ?? 0),
            'capacity_total' => (int) ($a['capacity_total'] ?? 0),
            'reserved_count' => (int) ($a['reserved_count'] ?? 0),
            'confirmed_count' => (int) ($a['confirmed_count'] ?? 0),
            'status' => (string) ($a['status'] ?? ''),
        ]);
    }

    /**
     * @param  array<string, mixed>  $order
     * @param  array<string, int|bool>  $result
     */
    private function upsertOrder(array $order, array &$result): void
    {
        $billing = Arr::only($order['billing'] ?? [], ['first_name', 'last_name', 'company', 'email', 'phone', 'city', 'state', 'state_name', 'country', 'country_name']);

        $registration = Registration::updateOrCreate(['wc_order_id' => (int) $order['id']], [
            'number' => (string) ($order['number'] ?? $order['id']),
            'status' => (string) ($order['status'] ?? ''),
            'currency' => (string) ($order['currency'] ?? 'COP'),
            'total' => (float) ($order['total'] ?? 0),
            'ordered_at' => $this->date($order['created_at'] ?? null),
            'paid_at' => $this->date($order['paid_at'] ?? null),
            'billing' => $billing,
            'edition_year' => $this->editionYear($order),
        ]);

        $paid = $registration->isPaid();
        $buyer = null;

        foreach ($order['participants'] ?? [] as $p) {
            $contact = $this->contactFor($p, $billing, $paid, $result);

            $participant = RegistrationParticipant::updateOrCreate(['evr_order_participant_id' => (int) $p['order_participant_id']], [
                'registration_id' => $registration->id,
                'contact_id' => $contact?->id,
                'evr_participant_id' => (int) ($p['participant_id'] ?? 0),
                'full_name' => Str::limit((string) ($p['full_name'] ?? ''), 250, ''),
                'email' => $this->email($p['email'] ?? null),
                'document_type' => $p['document_type'] ?: null,
                'document_number' => $p['document_number'] ?: null,
                'whatsapp' => $p['whatsapp'] ?: null,
                'shirt_size' => $p['shirt_size'] ?: null,
                'meal_type' => $p['meal_type'] ?: null,
                'allergies' => $p['allergies'] ?: null,
                'entity' => $p['entity'] ?: null,
                'registered_by' => $p['registered_by'] ?: null,
            ]);
            $result['participants']++;

            $active = [];
            foreach ($p['activities'] ?? [] as $a) {
                RegistrationActivity::updateOrCreate(['evr_assignment_id' => (int) $a['assignment_id']], [
                    'registration_participant_id' => $participant->id,
                    'evr_activity_id' => (int) $a['activity_id'],
                    'name' => $a['name'] ?? null,
                    'type' => $a['type'] ?? null,
                    'event_date' => $a['event_date'] ?? null,
                    'start_time' => $a['start_time'] ?? null,
                    'status' => (string) ($a['status'] ?? ''),
                    'price' => (float) ($a['price'] ?? 0),
                    'discount' => (float) ($a['discount'] ?? 0),
                    'total' => (float) ($a['total'] ?? 0),
                    'discount_label' => ($a['discount_label'] ?? null) ?: null,
                ]);
                if (in_array($a['status'] ?? '', RegistrationActivity::ACTIVE_STATUSES, true)) {
                    $active[] = $a['name'] ?? 'Actividad';
                }
            }

            if ($contact && $paid && $active && $registration->edition_year) {
                $this->recordEdition($contact, $registration, $p, $active);
            }

            if ($contact && $contact->email && $contact->email === $this->email($billing['email'] ?? null)) {
                $buyer = $contact;
            }
        }

        $registration->forceFill(['buyer_contact_id' => $buyer?->id ?? $registration->buyer_contact_id])->save();
    }

    /**
     * @param  array<string, mixed>  $p
     * @param  array<string, mixed>  $billing
     * @param  array<string, int|bool>  $result
     */
    private function contactFor(array $p, array $billing, bool $paid, array &$result): ?Contact
    {
        [$first, $last] = $this->splitName((string) ($p['full_name'] ?? ''));
        $data = array_filter([
            'first_name' => $first,
            'last_name' => $last,
            'email' => $this->email($p['email'] ?? null),
            'document_type' => $p['document_type'] ?? null,
            'document_number' => isset($p['document_number']) ? Str::upper(preg_replace('/[\s.\-]/', '', (string) $p['document_number'])) : null,
            'phone' => isset($p['whatsapp']) ? (preg_replace('/[^\d+]/', '', (string) $p['whatsapp']) ?: null) : null,
            'organization' => $p['entity'] ?? null,
            // Decision 2026-10-11: country and city come from the order's billing data.
            'country' => $billing['country_name'] ?? $billing['country'] ?? null,
            'state' => $billing['state_name'] ?? $billing['state'] ?? null,
            'city' => isset($billing['city']) ? Str::title(Str::lower($billing['city'])) : null,
        ], fn ($v) => $v !== null && $v !== '');

        if (empty($data['email']) && empty($data['document_number']) && empty($data['first_name'])) {
            return null;
        }

        $contact = $this->matcher->find($data);

        // The email may already belong to a relative who registered them; keep it with its owner.
        if (! empty($data['email']) && $contact?->email !== $data['email'] && Contact::where('email', $data['email'])->exists()) {
            unset($data['email']);
        }

        // Abandoned checkouts and failed payments only link to people we already know.
        if (! $contact && ! $paid) {
            return null;
        }

        if (! $contact) {
            $contact = new Contact(['source' => Contact::SOURCE_PLUGIN]);
            $result['new_contacts']++;
        }

        foreach ([...Contact::PROFILE_FIELDS, 'email'] as $field) {
            if (! empty($data[$field]) && blank($contact->{$field})) {
                $contact->{$field} = Str::limit((string) $data[$field], 120, '');
            }
        }

        // Buyers accept the privacy policy at checkout, so they are Activo unless they unsubscribed.
        if ($paid && $contact->email && $contact->unsubscribed_at === null && ! $contact->marketing_consent) {
            $contact->grantConsent('Compra en el plugin de inscripciones');
        }
        $contact->save();

        return $contact;
    }

    /**
     * @param  array<string, mixed>  $p
     * @param  list<string>  $activities
     */
    private function recordEdition(Contact $contact, Registration $registration, array $p, array $activities): void
    {
        if (! $contact->is_attendee) {
            $contact->forceFill(['is_attendee' => true])->save();
        }

        $edition = ContactEdition::firstOrNew(['contact_id' => $contact->id, 'year' => $registration->edition_year, 'role' => ContactEdition::ROLE_ATTENDEE]);
        $edition->details = array_merge($edition->details ?? [], array_filter([
            'Pedido' => '#'.$registration->number,
            'Actividades' => implode(' · ', array_unique($activities)),
            'Camisa' => $p['shirt_size'] ?? null,
            'Alimentación' => $p['meal_type'] ?? null,
            'Fuente' => 'Plugin de inscripciones',
        ]));
        $edition->save();

        $this->insights->refresh($contact);
    }

    /**
     * Year of the earliest dated activity, or of the order date.
     *
     * @param  array<string, mixed>  $order
     */
    private function editionYear(array $order): ?int
    {
        $dates = collect($order['participants'] ?? [])->flatMap(fn ($p) => $p['activities'] ?? [])->pluck('event_date')->filter()->sort();

        if ($dates->isNotEmpty()) {
            return (int) substr($dates->first(), 0, 4);
        }

        return $this->date($order['created_at'] ?? null)?->year;
    }

    private function date(?string $value): ?Carbon
    {
        return $value ? Carbon::parse($value)->utc() : null;
    }

    private function email(?string $value): ?string
    {
        $email = Str::lower(trim((string) $value));

        return filter_var($email, FILTER_VALIDATE_EMAIL) ? $email : null;
    }

    /**
     * @return array{0: string|null, 1: string|null}
     */
    private function splitName(string $name): array
    {
        $parts = array_values(array_filter(explode(' ', Str::title(Str::lower(trim(preg_replace('/\s+/u', ' ', $name)))))));

        return match (count($parts)) {
            0 => [null, null],
            1 => [$parts[0], null],
            2 => [$parts[0], $parts[1]],
            3 => [$parts[0], "{$parts[1]} {$parts[2]}"],
            default => [implode(' ', array_slice($parts, 0, -2)), implode(' ', array_slice($parts, -2))],
        };
    }
}
