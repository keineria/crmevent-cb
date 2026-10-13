<?php

namespace App\Services\Reports;

use App\Models\CheckIn;
use App\Models\Contact;
use App\Models\ContactEdition;
use App\Models\Registration;
use App\Models\RegistrationActivity;
use App\Models\RegistrationParticipant;
use App\Services\Contacts\ImportFields;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Throwable;

/**
 * Attendees of a past fair loaded from its registration master (Contactos › Importar), shaped like plugin participants
 * so the edition report counts them the same way.
 *
 * Nothing is copied into the registration tables: each imported attendee becomes an unsaved participant built from the
 * contact (name, document, country, city, sex) and the columns kept as details of that person's fair (plan, outings
 * per day, workshops, shirt, meal, purchase date). Activities get stable negative ids so their cards and people lists
 * match across requests and never clash with plugin activity ids.
 */
class ImportedEdition
{
    public const ORDER_LABEL = 'Importado';

    /** Detail column (normalized header) => [activity type, days after the fair's Thursday]. Null type: decided by name. */
    private const DAY_COLUMNS = [
        'jueves' => [null, 0],
        'jueves taller' => ['workshop', 0],
        'viernes' => ['birding', 1],
        'vie taller' => ['workshop', 1],
        'sabado' => ['birding', 2],
        'sab taller' => ['workshop', 2],
        'domingo' => ['birding', 3],
        'dom taller' => ['workshop', 3],
    ];

    private const MEALS = ['tradicional' => 'traditional', 'vegetariano' => 'vegetarian', 'vegetariana' => 'vegetarian', 'vegano' => 'vegan', 'vegana' => 'vegan'];

    private const SIZES = ['XS', 'S', 'M', 'L', 'XL', 'XXL', 'XXXL'];

    /** @var array<int, array{id: int, name: string, type: string, date: ?string, price: float}> */
    private array $activities = [];

    private ?Collection $participants = null;

    public function __construct(
        public readonly int $year,
        public readonly ?Carbon $from = null,
        public readonly ?Carbon $to = null,
    ) {}

    /**
     * Years with imported attendees.
     *
     * @return list<int>
     */
    public static function years(): array
    {
        return ContactEdition::query()->where('role', ContactEdition::ROLE_ATTENDEE)->distinct()
            ->pluck('year')->map(fn ($y) => (int) $y)->all();
    }

    /**
     * Imported attendees of the edition whose registration date falls in the range, leaving out contacts the plugin
     * already brought for that edition.
     *
     * @param  Collection<int, int>  $skipContacts
     * @return Collection<int, RegistrationParticipant>
     */
    public function participants(Collection $skipContacts): Collection
    {
        if ($this->participants !== null) {
            return $this->participants;
        }

        $editions = ContactEdition::query()
            ->where('year', $this->year)
            ->where('role', ContactEdition::ROLE_ATTENDEE)
            ->whereNotIn('contact_id', $skipContacts->filter()->unique()->values())
            ->with(['contact' => fn ($q) => $q->withExists(['editions as returning' => fn ($e) => $e
                ->where('role', ContactEdition::ROLE_ATTENDEE)
                ->where('year', '<', $this->year)])])
            ->get()
            ->filter(fn (ContactEdition $e) => $e->contact !== null);

        $rows = $editions->map(function (ContactEdition $e) {
            $details = $this->details($e);

            return ['edition' => $e, 'details' => $details, 'ordered_at' => $this->date($details['fecha'] ?? null)];
        });
        $start = $this->fairStart($rows->pluck('ordered_at')->filter());

        // A workshop counts as paid when someone paid for workshops and took it; courtesy seats alone don't make it paid.
        $paidWorkshops = [];
        foreach ($rows as $r) {
            if ($this->amount($r['details']['valor talleres'] ?? null) > 0) {
                foreach ($this->activityList($r['details'], $start) as $a) {
                    $paidWorkshops[$a['id']] = true;
                }
            }
        }

        return $this->participants = $rows
            ->filter(fn ($r) => $this->inRange($r['ordered_at']))
            ->map(function ($r) use ($start, $paidWorkshops) {
                $activities = collect($this->activityList($r['details'], $start))->map(function ($a) use ($paidWorkshops) {
                    $a['price'] = $a['type'] === 'workshop' && isset($paidWorkshops[$a['id']]) ? 1.0 : 0.0;
                    $this->activities[$a['id']] = $a;

                    return (new RegistrationActivity)->forceFill([
                        'evr_activity_id' => $a['id'], 'name' => $a['name'], 'type' => $a['type'],
                        'event_date' => $a['date'], 'status' => 'confirmed', 'total' => $a['price'],
                    ]);
                });

                return $this->participant($r['edition'], $r['details'], $r['ordered_at'], $activities);
            })
            ->values();
    }

    /**
     * Activities found in the imported rows, by their negative id.
     *
     * @return array<int, array{id: int, name: string, type: string, date: ?string, price: float}>
     */
    public function activities(): array
    {
        return $this->activities;
    }

    /**
     * @param  array<string, string>  $details
     * @param  Collection<int, RegistrationActivity>  $activities
     */
    private function participant(ContactEdition $edition, array $details, ?Carbon $orderedAt, Collection $activities): RegistrationParticipant
    {
        /** @var Contact $contact */
        $contact = $edition->contact;
        $size = strtoupper(trim($details['camisa'] ?? ''));

        $registration = (new Registration)->forceFill([
            'id' => -$edition->id,
            'number' => self::ORDER_LABEL,
            'status' => 'completed',
            'edition_year' => $this->year,
            // Stored like synced orders: in UTC, read back in Bogotá.
            'ordered_at' => $orderedAt?->copy()->utc(),
            'billing' => array_filter(['country_name' => $contact->country, 'city' => $contact->city, 'phone' => $contact->phone]),
        ]);

        $participant = (new RegistrationParticipant)->forceFill([
            'id' => -$edition->id,
            'registration_id' => -$edition->id,
            'contact_id' => $contact->id,
            'full_name' => $contact->fullName(),
            'email' => $contact->email,
            'document_type' => $contact->document_type,
            'document_number' => $contact->document_number,
            'whatsapp' => $contact->phone,
            'shirt_size' => in_array($size, self::SIZES, true) ? $size : null,
            'meal_type' => self::MEALS[ImportFields::normalize($details['alimentacion'] ?? '')] ?? null,
            'returning' => (bool) $contact->returning,
        ]);

        return $participant
            ->setRelation('registration', $registration)
            ->setRelation('activities', new EloquentCollection($activities->all()))
            ->setRelation('checkIns', new EloquentCollection)
            ->setRelation('contact', $contact);
    }

    /**
     * Activities of one imported row: conferences, the launch, and each day's outing and workshop.
     *
     * @param  array<string, string>  $details
     * @return list<array{id: int, name: string, type: string, date: ?string, price: float}>
     */
    private function activityList(array $details, ?Carbon $start): array
    {
        $day = fn (int $offset) => $start?->copy()->addDays($offset)->toDateString();
        $list = [];

        if ($this->yes($details['conferencias'] ?? null)) {
            $list[] = $this->activity('Conferencias', 'conference', $day(0));
        }
        $launch = $details['lanzamiento'] ?? '';
        if ($launch !== '' && ! $this->no($launch)) {
            $list[] = $this->activity('Lanzamiento', 'other', $day(0));
        }

        foreach (self::DAY_COLUMNS as $column => [$type, $offset]) {
            $name = trim(preg_replace('/\s+/u', ' ', $details[$column] ?? ''));
            if ($name === '' || $this->no($name) || $this->yes($name)) {
                continue;
            }
            // Launches and concerts were written in the day columns too; they are neither outings nor workshops.
            $type = preg_match('/lanzamiento|concierto/', ImportFields::normalize($name)) ? 'other' : ($type ?? 'workshop');
            $list[] = $this->activity($name, $type, $day($offset));
        }

        return $list;
    }

    /**
     * @return array{id: int, name: string, type: string, date: ?string, price: float}
     */
    private function activity(string $name, string $type, ?string $date): array
    {
        // Same name on another day is another outing; the id must not depend on how the name was capitalised.
        $id = -1 - (crc32("{$this->year}|{$type}|{$date}|".ImportFields::normalize($name)) & 0x3FFFFFFF);

        return ['id' => $id, 'name' => $this->activities[$id]['name'] ?? $name, 'type' => $type, 'date' => $date, 'price' => 0.0];
    }

    /**
     * The fair's Thursday: configured for known editions, otherwise the first Thursday on or after the last registration.
     *
     * @param  Collection<int, Carbon>  $dates
     */
    private function fairStart(Collection $dates): ?Carbon
    {
        $configured = config('crm.fair_start_dates')[$this->year] ?? null;
        if ($configured) {
            return Carbon::parse($configured, CheckIn::TIMEZONE)->startOfDay();
        }

        $last = $dates->max();

        return $last ? ($last->isThursday() ? $last->copy() : $last->copy()->next(Carbon::THURSDAY)) : null;
    }

    /**
     * @return array<string, string> details keyed by normalized header
     */
    private function details(ContactEdition $edition): array
    {
        $details = [];
        foreach ($edition->details ?? [] as $header => $value) {
            $key = ImportFields::normalize((string) $header);
            if ($key !== '' && ! isset($details[$key])) {
                $details[$key] = trim((string) $value);
            }
        }

        return $details;
    }

    private function date(?string $value): ?Carbon
    {
        if (! $value) {
            return null;
        }

        try {
            $date = is_numeric($value)
                // Excel serial day, when the cell came as a number.
                ? Carbon::create(1899, 12, 30, 0, 0, 0, CheckIn::TIMEZONE)->addDays((int) $value)
                : Carbon::parse($value, CheckIn::TIMEZONE)->startOfDay();
        } catch (Throwable) {
            return null;
        }

        return $date->year >= 2000 && $date->year <= 2100 ? $date : null;
    }

    private function inRange(?Carbon $date): bool
    {
        if (! $this->from && ! $this->to) {
            return true;
        }

        return $date !== null
            && (! $this->from || $date->gte($this->from->copy()->startOfDay()))
            && (! $this->to || $date->lte($this->to->copy()->endOfDay()));
    }

    private function amount(?string $value): float
    {
        return (float) preg_replace('/[^\d]/', '', (string) $value);
    }

    private function yes(?string $value): bool
    {
        return in_array(ImportFields::normalize((string) $value), ['si', 'x', 'yes'], true);
    }

    private function no(?string $value): bool
    {
        return in_array(ImportFields::normalize((string) $value), ['no', '0'], true);
    }
}
