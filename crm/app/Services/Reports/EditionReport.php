<?php

namespace App\Services\Reports;

use App\Models\CheckIn;
use App\Models\ContactEdition;
use App\Models\EventActivity;
use App\Models\Registration;
use App\Models\RegistrationActivity;
use App\Models\RegistrationParticipant;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Numbers of one fair edition, built from paid registrations synced from the plugin and the CRM's check-ins.
 *
 * Every section is a list of cards; every card can list the people behind its number. The whole edition is
 * loaded once per request (a fair has a few thousand participants at most), so cards and their people lists
 * always agree.
 */
class EditionReport
{
    /** Section key => title, in the order the page shows them. Finanzas adds its own sections here. */
    public const SECTIONS = [
        'salidas' => 'Salidas',
        'conferencias' => 'Conferencias',
        'talleres-pagos' => 'Talleres pagos',
        'talleres-gratuitos' => 'Talleres gratuitos',
        'otras' => 'Otras actividades',
        'checkin' => 'Activación del registro',
        'tallas' => 'Tallas de camiseta',
        'alimentacion' => 'Alimentación',
        'paises' => 'Países',
        'ciudades' => 'Ciudades',
        'recurrencia' => 'Ferias anteriores',
    ];

    public const MEALS = ['traditional' => 'Tradicional', 'vegetarian' => 'Vegetariana', 'vegan' => 'Vegana'];

    private const NONE = '_';

    /** @var Collection<int, RegistrationParticipant>|null */
    private ?Collection $participants = null;

    /** @var Collection<int, EventActivity>|null */
    private ?Collection $activities = null;

    public function __construct(
        public readonly int $year,
        public readonly ?Carbon $from = null,
        public readonly ?Carbon $to = null,
    ) {}

    /**
     * Fair editions that have registrations, newest first.
     *
     * @return list<int>
     */
    public static function editions(): array
    {
        return Registration::query()->whereNotNull('edition_year')->distinct()->orderByDesc('edition_year')
            ->pluck('edition_year')->map(fn ($y) => (int) $y)->all();
    }

    /**
     * Paid participants of the edition whose order falls in the date range (Bogotá days).
     */
    public function query(): Builder
    {
        return RegistrationParticipant::query()->whereHas('registration', fn (Builder $r) => $r
            ->whereIn('status', Registration::PAID_STATUSES)
            ->where('edition_year', $this->year)
            ->when($this->from, fn (Builder $q, Carbon $from) => $q->where('ordered_at', '>=', $from->copy()->startOfDay()->utc()))
            ->when($this->to, fn (Builder $q, Carbon $to) => $q->where('ordered_at', '<=', $to->copy()->endOfDay()->utc())));
    }

    /**
     * @return Collection<int, RegistrationParticipant>
     */
    public function participants(): Collection
    {
        return $this->participants ??= $this->query()
            ->with([
                'registration:id,number,ordered_at,billing',
                'activities' => fn ($q) => $q->whereIn('status', RegistrationActivity::ACTIVE_STATUSES)
                    ->select('id', 'registration_participant_id', 'evr_activity_id', 'name', 'type', 'event_date', 'start_time', 'total'),
                'checkIns:id,registration_participant_id,registration_activity_id',
            ])
            ->withExists(['contact as returning' => fn ($q) => $q->whereHas('editions', fn ($e) => $e
                ->where('role', ContactEdition::ROLE_ATTENDEE)
                ->where('year', '<', $this->year))])
            ->orderBy('full_name')
            ->get();
    }

    /**
     * Headline numbers for the top of the page.
     *
     * @return array<string, int>
     */
    public function summary(): array
    {
        $people = $this->participants();

        return [
            'participants' => $people->count(),
            'orders' => $people->pluck('registration_id')->unique()->count(),
            'activated' => $people->filter(fn ($p) => $p->checkIns->isNotEmpty())->count(),
            'returning' => $people->filter(fn ($p) => $p->returning)->count(),
            'countries' => $people->map(fn ($p) => $this->country($p))->filter()->unique()->count(),
            'trips' => $people->sum(fn ($p) => $p->activities->where('type', 'birding')->count()),
        ];
    }

    /**
     * New participants per Bogotá day, with no gaps, across the range (or the edition's first to last sale).
     *
     * @return list<array{date: string, participants: int}>
     */
    public function timeline(): array
    {
        $byDay = $this->participants()
            ->groupBy(fn ($p) => $p->registration->ordered_at?->copy()->setTimezone(CheckIn::TIMEZONE)->toDateString())
            ->forget('')
            ->map->count();

        if ($byDay->isEmpty() && ! ($this->from && $this->to)) {
            return [];
        }

        $start = $this->from?->copy() ?? Carbon::parse($byDay->keys()->min(), CheckIn::TIMEZONE);
        $end = $this->to?->copy() ?? Carbon::parse($byDay->keys()->max(), CheckIn::TIMEZONE);
        // Keep the chart readable and the payload small when someone picks a huge range.
        if ($start->diffInDays($end) > 400) {
            $start = $end->copy()->subDays(400);
        }

        $days = [];
        for ($d = $start->copy()->startOfDay(); $d->lte($end); $d->addDay()) {
            $days[] = ['date' => $d->toDateString(), 'participants' => (int) ($byDay[$d->toDateString()] ?? 0)];
        }

        return $days;
    }

    /**
     * Cards of one section. Activity cards carry their seats; the rest carry only a count.
     *
     * @return list<array<string, mixed>>
     */
    public function section(string $section): array
    {
        $people = $this->participants();

        return match ($section) {
            'salidas' => $this->activityCards('birding'),
            'conferencias' => $this->activityCards('conference'),
            'talleres-pagos' => $this->activityCards('workshop', paid: true),
            'talleres-gratuitos' => $this->activityCards('workshop', paid: false),
            'otras' => $this->activityCards('other'),
            'checkin' => [
                $this->card('si', 'Activaron su registro', $people->filter(fn ($p) => $p->checkIns->isNotEmpty())->count(), total: $people->count()),
                $this->card('no', 'Aún sin activar', $people->filter(fn ($p) => $p->checkIns->isEmpty())->count(), total: $people->count()),
            ],
            'recurrencia' => [
                $this->card('primera', 'Primera feria', $people->reject(fn ($p) => $p->returning)->count(), total: $people->count()),
                $this->card('repite', 'Vinieron antes', $people->filter(fn ($p) => $p->returning)->count(), total: $people->count()),
            ],
            'tallas', 'alimentacion', 'paises', 'ciudades' => $this->groupCards($section),
            default => abort(404),
        };
    }

    /**
     * People behind one card.
     *
     * @return Collection<int, RegistrationParticipant>
     */
    public function people(string $section, string $card): Collection
    {
        $people = $this->participants();

        return match ($section) {
            'salidas', 'conferencias', 'talleres-pagos', 'talleres-gratuitos', 'otras' => $people
                ->filter(fn ($p) => $p->activities->contains('evr_activity_id', (int) $card))->values(),
            'checkin' => $people->filter(fn ($p) => $p->checkIns->isNotEmpty() === ($card === 'si'))->values(),
            'recurrencia' => $people->filter(fn ($p) => (bool) $p->returning === ($card === 'repite'))->values(),
            'tallas', 'alimentacion', 'paises', 'ciudades' => $people
                ->filter(fn ($p) => ($this->groupValue($section, $p) ?? self::NONE) === $card)->values(),
            default => abort(404),
        };
    }

    /**
     * Title of a card, for the people dialog and the CSV file name.
     */
    public function cardLabel(string $section, string $card): string
    {
        $match = collect($this->section($section))->firstWhere('key', $card);

        return $match['label'] ?? $card;
    }

    /**
     * Whether a participant checked in to the activity behind an activity card (or to anything, elsewhere).
     */
    public function checkedIn(RegistrationParticipant $p, string $section, string $card): bool
    {
        if (in_array($section, ['salidas', 'conferencias', 'talleres-pagos', 'talleres-gratuitos', 'otras'], true)) {
            $assignment = $p->activities->firstWhere('evr_activity_id', (int) $card);

            return $assignment !== null && $p->checkIns->contains('registration_activity_id', $assignment->id);
        }

        return $p->checkIns->isNotEmpty();
    }

    public function country(RegistrationParticipant $p): ?string
    {
        $billing = $p->registration->billing ?? [];

        return ($billing['country_name'] ?? null) ?: (($billing['country'] ?? null) ?: null);
    }

    public function city(RegistrationParticipant $p): ?string
    {
        $city = trim((string) ($p->registration->billing['city'] ?? ''));

        return $city === '' ? null : mb_convert_case($city, MB_CASE_TITLE);
    }

    public function phone(RegistrationParticipant $p): ?string
    {
        return $p->whatsapp ?: (($p->registration->billing['phone'] ?? null) ?: null);
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function activityCards(string $type, ?bool $paid = null): array
    {
        $counts = [];
        $checked = [];
        foreach ($this->participants() as $p) {
            foreach ($p->activities as $a) {
                if ($a->type !== $type) {
                    continue;
                }
                $counts[$a->evr_activity_id] = ($counts[$a->evr_activity_id] ?? 0) + 1;
                if ($p->checkIns->contains('registration_activity_id', $a->id)) {
                    $checked[$a->evr_activity_id] = ($checked[$a->evr_activity_id] ?? 0) + 1;
                }
            }
        }

        $this->activities ??= EventActivity::query()->where('status', '!=', 'archived')->get()->keyBy('evr_id');
        $catalog = $this->activities->filter(fn (EventActivity $a) => $a->type === $type
            && (($a->event_date?->year ?? $this->year) === $this->year || isset($counts[$a->evr_id])));

        // Activities sold earlier but no longer in the catalog still show, named from the registration.
        $missing = collect(array_keys($counts))->reject(fn ($id) => $catalog->has($id));
        $names = $missing->isEmpty() ? collect() : RegistrationActivity::whereIn('evr_activity_id', $missing)
            ->get(['evr_activity_id', 'name', 'event_date', 'start_time', 'total'])->keyBy('evr_activity_id');

        $cards = $catalog->map(fn (EventActivity $a) => [
            'id' => $a->evr_id, 'name' => $a->name, 'destination' => $a->destination,
            'date' => $a->event_date?->toDateString(), 'time' => $a->start_time ? substr($a->start_time, 0, 5) : null,
            'price' => (float) $a->price, 'capacity' => $a->capacity_total,
            'occupied' => $a->confirmed_count + $a->reserved_count,
        ])->values()->concat($missing->map(function ($id) use ($names, $counts) {
            $sold = $names->get($id);

            return [
                'id' => $id, 'name' => $sold->name ?? "Actividad {$id}", 'destination' => null,
                'date' => $sold?->event_date?->toDateString(), 'time' => $sold?->start_time ? substr($sold->start_time, 0, 5) : null,
                'price' => (float) ($sold->total ?? 0), 'capacity' => 0, 'occupied' => $counts[$id],
            ];
        }));

        if ($paid !== null) {
            $cards = $cards->filter(fn ($c) => ($c['price'] > 0) === $paid);
        }

        return $cards
            ->sortBy(fn ($c) => [$c['date'] ?? '9999', $c['time'] ?? '99', $c['name']])
            ->map(fn ($c) => $this->card((string) $c['id'], $c['name'], $counts[$c['id']] ?? 0, extra: [
                'date' => $c['date'],
                'time' => $c['time'],
                'destination' => $c['destination'],
                'capacity' => $c['capacity'],
                'available' => $c['capacity'] > 0 ? max(0, $c['capacity'] - $c['occupied']) : null,
                'checked_in' => $checked[$c['id']] ?? 0,
            ]))
            ->values()
            ->all();
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function groupCards(string $section): array
    {
        $people = $this->participants();
        $groups = $people->countBy(fn ($p) => $this->groupValue($section, $p) ?? self::NONE);
        $none = $groups->pull(self::NONE);

        $order = match ($section) {
            'tallas' => ['XS', 'S', 'M', 'L', 'XL', 'XXL', 'XXXL'],
            'alimentacion' => array_keys(self::MEALS),
            default => null,
        };
        $groups = $order
            ? $groups->sortBy(fn ($n, $k) => array_search($k, $order, true) === false ? 99 : array_search($k, $order, true))
            : $groups->sortDesc();

        $cards = $groups->map(fn ($n, $k) => $this->card((string) $k, $section === 'alimentacion' ? (self::MEALS[$k] ?? $k) : (string) $k, $n, total: $people->count()))
            ->values();
        if ($none) {
            $cards->push($this->card(self::NONE, 'Sin dato', $none, total: $people->count()));
        }

        return $cards->all();
    }

    private function groupValue(string $section, RegistrationParticipant $p): ?string
    {
        return match ($section) {
            'tallas' => $p->shirt_size ? strtoupper($p->shirt_size) : null,
            'alimentacion' => $p->meal_type ?: null,
            'paises' => $this->country($p),
            'ciudades' => $this->city($p),
        };
    }

    /**
     * @param  array<string, mixed>  $extra
     * @return array<string, mixed>
     */
    private function card(string $key, string $label, int $count, ?int $total = null, array $extra = []): array
    {
        return ['key' => $key, 'label' => $label, 'count' => $count, 'total' => $total] + $extra;
    }
}
