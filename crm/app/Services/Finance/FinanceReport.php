<?php

namespace App\Services\Finance;

use App\Models\EventActivity;
use App\Models\Expense;
use App\Models\Registration;
use App\Models\RegistrationActivity;
use App\Models\RegistrationParticipant;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Sales, costs and profit of one edition, optionally for a date range.
 *
 * Sales are the plugin's paid orders (processing/completed). A sale counts on the day it was paid, in Bogotá time.
 * Order totals are the money received; activity totals are what each seat was sold for. The two differ when the
 * desk changed someone to a cheaper activity (no refunds) or the order carries a fee, so the gap is shown apart.
 * Fixed costs count on the day they were spent; a per-person cost counts once for each paid seat, on its sale day.
 */
class FinanceReport
{
    public const TIMEZONE = 'America/Bogota';

    /** Longest daily series drawn, so a stray old date cannot produce thousands of points. */
    private const MAX_DAYS = 400;

    private int $year;

    private ?string $from;

    private ?string $to;

    /** @var Collection<int, object> paid seats: evr_activity_id, name, type, price, discount, total, day */
    private Collection $seats;

    /** @var Collection<int, object> paid orders: total, day */
    private Collection $orders;

    /** @var Collection<int, string> sale day of every participant in a paid order */
    private Collection $participants;

    /** @var Collection<int, Expense> */
    private Collection $expenses;

    /** Cost lines, worked out once. */
    private ?Collection $lines = null;

    public function __construct(int $year, ?string $from = null, ?string $to = null)
    {
        $this->year = $year;
        $this->from = $from;
        $this->to = $to;
        $this->load();
    }

    /**
     * Editions that have orders or costs, newest first. The latest is the default.
     *
     * @return list<int>
     */
    public static function editions(): array
    {
        return Registration::query()->whereNotNull('edition_year')->distinct()->pluck('edition_year')
            ->merge(Expense::query()->distinct()->pluck('edition_year'))
            ->map(fn ($y) => (int) $y)
            ->unique()->sortDesc()->values()->all();
    }

    public static function defaultEdition(): int
    {
        return self::editions()[0] ?? (int) now(self::TIMEZONE)->year;
    }

    private function load(): void
    {
        $paidScope = fn ($q) => $q->whereIn('registrations.status', Registration::PAID_STATUSES)->where('registrations.edition_year', $this->year);

        $this->seats = RegistrationActivity::query()
            ->join('registration_participants', 'registration_participants.id', '=', 'registration_activities.registration_participant_id')
            ->join('registrations', 'registrations.id', '=', 'registration_participants.registration_id')
            ->where($paidScope)
            ->whereIn('registration_activities.status', RegistrationActivity::ACTIVE_STATUSES)
            ->get([
                'registration_activities.evr_activity_id', 'registration_activities.name', 'registration_activities.type',
                'registration_activities.price', 'registration_activities.discount', 'registration_activities.total',
                DB::raw('COALESCE(registrations.paid_at, registrations.ordered_at) as sold_at'),
            ])
            ->map(fn ($s) => (object) [
                'activity' => (int) $s->evr_activity_id,
                'name' => $s->name,
                'type' => $s->type,
                'price' => (float) $s->price,
                'discount' => (float) $s->discount,
                'total' => (float) $s->total,
                'day' => $this->day($s->sold_at),
            ])
            ->filter(fn ($s) => $this->inRange($s->day))
            ->values();

        $this->orders = Registration::query()->where($paidScope)
            ->get(['total', DB::raw('COALESCE(paid_at, ordered_at) as sold_at')])
            ->map(fn ($o) => (object) ['total' => (float) $o->total, 'day' => $this->day($o->sold_at)])
            ->filter(fn ($o) => $this->inRange($o->day))
            ->values();

        $this->participants = RegistrationParticipant::query()
            ->join('registrations', 'registrations.id', '=', 'registration_participants.registration_id')
            ->where($paidScope)
            ->get([DB::raw('COALESCE(registrations.paid_at, registrations.ordered_at) as sold_at')])
            ->map(fn ($p) => $this->day($p->sold_at))
            ->filter(fn ($d) => $this->inRange($d))
            ->values();

        $this->expenses = Expense::query()->where('edition_year', $this->year)->get();
    }

    /**
     * Everything the finance panel shows.
     *
     * @return array<string, mixed>
     */
    public function summary(): array
    {
        $activities = $this->activities();
        $other = $this->otherCosts();

        $income = round($this->orders->sum('total'), 2);
        $activitySales = round($this->seats->sum('total'), 2);
        $activityCosts = round(array_sum(array_column($activities, 'costs')), 2);
        $otherCosts = round(array_sum(array_column($other, 'amount')), 2);
        $costs = $activityCosts + $otherCosts;
        $courtesies = $this->seats->filter(fn ($s) => self::isCourtesy($s));

        return [
            'kpis' => [
                'income' => $income,
                'activity_sales' => $activitySales,
                'adjustments' => round($income - $activitySales, 2),
                'costs' => round($costs, 2),
                'activity_costs' => $activityCosts,
                'other_costs' => $otherCosts,
                'unpaid_costs' => round($this->costLines()->where('paid', false)->sum('amount'), 2),
                'profit' => round($income - $costs, 2),
                'margin' => $income > 0 ? round(($income - $costs) / $income * 100, 1) : null,
                'orders' => $this->orders->count(),
                'participants' => $this->participants->count(),
                'seats' => $this->seats->count(),
                'courtesies' => $courtesies->count(),
                'courtesy_value' => round($courtesies->sum('price'), 2),
                // Courtesies show apart, so discounts only count seats that still paid something.
                'discounts' => round($this->seats->filter(fn ($s) => $s->total > 0)->sum('discount'), 2),
            ],
            'activities' => $activities,
            'by_type' => $this->byType(),
            'by_category' => $this->byCategory(),
            'series' => $this->series(),
        ];
    }

    /**
     * One row per activity of the edition with its sales, costs and break-even.
     *
     * @return list<array<string, mixed>>
     */
    public function activities(): array
    {
        $seats = $this->seats->groupBy('activity');
        $costs = $this->costLines()->whereNotNull('activity')->groupBy('activity');

        $catalog = EventActivity::query()
            ->where(fn ($q) => $q->whereYear('event_date', $this->year)
                ->orWhereIn('evr_id', $seats->keys()->merge($costs->keys())->all()))
            ->get()->keyBy('evr_id');

        return $catalog->keys()->merge($seats->keys())->merge($costs->keys())->unique()
            ->map(function ($id) use ($catalog, $seats, $costs) {
                $activity = $catalog->get($id);
                $sold = $seats->get($id, collect());
                $lines = $costs->get($id, collect());
                $first = $sold->first();

                $paying = $sold->filter(fn ($s) => $s->total > 0);
                $sales = round($sold->sum('total'), 2);
                $fixed = round($lines->where('mode', Expense::MODE_FIXED)->sum('amount'), 2);
                $variable = round($lines->where('mode', Expense::MODE_PER_PERSON)->sum('amount'), 2);
                $total = $fixed + $variable;

                return [
                    'id' => (int) $id,
                    'name' => $activity->name ?? $first->name ?? "Actividad #{$id}",
                    'type' => $activity->type ?? $first->type ?? 'other',
                    'date' => $activity?->event_date?->toDateString(),
                    'start_time' => $activity?->start_time ? substr($activity->start_time, 0, 5) : null,
                    'price' => (float) ($activity->price ?? 0),
                    'capacity' => (int) ($activity->capacity_total ?? 0),
                    'participants' => $sold->count(),
                    'paying' => $paying->count(),
                    'courtesies' => $sold->filter(fn ($s) => self::isCourtesy($s))->count(),
                    'sales' => $sales,
                    'discounts' => round($paying->sum('discount'), 2),
                    'fixed_costs' => $fixed,
                    'variable_costs' => $variable,
                    'costs' => round($total, 2),
                    'cost_lines' => $lines->count(),
                    'profit' => round($sales - $total, 2),
                    'margin' => $sales > 0 ? round(($sales - $total) / $sales * 100, 1) : null,
                    'break_even' => $this->breakEven($activity, $paying, $sales, $fixed, $lines),
                ];
            })
            ->sortBy([['date', 'asc'], ['start_time', 'asc'], ['name', 'asc']])
            ->values()->all();
    }

    /**
     * Paying participants needed to cover the fixed costs: fixed / (average ticket - per-person cost).
     * Null when nothing is fixed or a seat does not even cover its own per-person cost.
     */
    private function breakEven(?EventActivity $activity, Collection $paying, float $sales, float $fixed, Collection $lines): ?int
    {
        if ($fixed <= 0) {
            return null;
        }

        $ticket = $paying->isNotEmpty() ? $sales / $paying->count() : (float) ($activity->price ?? 0);
        $perPerson = (float) $lines->where('mode', Expense::MODE_PER_PERSON)->sum('unit');
        $margin = $ticket - $perPerson;

        return $margin > 0 ? (int) ceil($fixed / $margin) : null;
    }

    /**
     * Costs not tied to an activity, grouped by category.
     *
     * @return list<array{category: string, label: string, amount: float}>
     */
    private function otherCosts(): array
    {
        return $this->costLines()->whereNull('activity')->groupBy('category')
            ->map(fn ($lines, $category) => [
                'category' => $category,
                'label' => Expense::CATEGORIES[$category] ?? $category,
                'amount' => round($lines->sum('amount'), 2),
            ])
            ->values()->all();
    }

    /** @return list<array{type: string, label: string, sales: float, seats: int}> */
    private function byType(): array
    {
        return collect(EventActivity::TYPE_LABELS)
            ->map(function ($label, $type) {
                $seats = $this->seats->where('type', $type);

                return ['type' => $type, 'label' => $label, 'sales' => round($seats->sum('total'), 2), 'seats' => $seats->count()];
            })
            ->values()->all();
    }

    /** @return list<array{category: string, label: string, amount: float}> */
    private function byCategory(): array
    {
        return $this->costLines()->groupBy('category')
            ->map(fn ($lines, $category) => [
                'category' => $category,
                'label' => Expense::CATEGORIES[$category] ?? $category,
                'amount' => round($lines->sum('amount'), 2),
            ])
            ->filter(fn ($row) => $row['amount'] > 0)
            ->sortByDesc('amount')
            ->values()->all();
    }

    /**
     * Daily income and costs, with every day of the range present.
     *
     * @return list<array{date: string, income: float, costs: float}>
     */
    private function series(): array
    {
        $income = $this->orders->groupBy('day')->map(fn ($o) => $o->sum('total'));
        $costs = collect();
        foreach ($this->costEvents() as [$day, $amount]) {
            $costs[$day] = ($costs[$day] ?? 0) + $amount;
        }

        $days = $income->keys()->merge($costs->keys())->filter()->sort()->values();
        $start = $this->from ?? $days->first();
        $end = $this->to ?? $days->last();
        if (! $start || ! $end || $start > $end) {
            return [];
        }

        $series = [];
        $cursor = Carbon::parse($start);
        $last = Carbon::parse($end);
        if ($cursor->diffInDays($last) > self::MAX_DAYS) {
            $cursor = $last->copy()->subDays(self::MAX_DAYS);
        }
        for (; $cursor->lte($last); $cursor->addDay()) {
            $d = $cursor->toDateString();
            $series[] = ['date' => $d, 'income' => round((float) ($income[$d] ?? 0), 2), 'costs' => round((float) ($costs[$d] ?? 0), 2)];
        }

        return $series;
    }

    /**
     * Each cost with the amount it adds up to in the range.
     *
     * @return Collection<int, array{id: int, activity: ?int, category: string, mode: string, unit: float, amount: float, paid: bool}>
     */
    public function costLines(): Collection
    {
        return $this->lines ??= $this->expenses
            ->map(fn (Expense $e) => [
                'id' => $e->id,
                'activity' => $e->evr_activity_id ? (int) $e->evr_activity_id : null,
                'category' => $e->category,
                'mode' => $e->mode,
                'unit' => (float) $e->unit_amount,
                'paid' => $e->paid,
                'people' => $e->isPerPerson() ? $this->peopleFor($e) : null,
                'amount' => $e->isPerPerson()
                    ? round((float) $e->unit_amount * $this->peopleFor($e), 2)
                    : ($this->inRange($e->spent_on->toDateString()) ? $e->fixedAmount() : 0.0),
            ])
            ->values();
    }

    /** Paid seats of the cost's activity, or every paid participant for a per-person cost of the whole fair. */
    public function peopleFor(Expense $expense): int
    {
        return $expense->evr_activity_id
            ? $this->seats->where('activity', (int) $expense->evr_activity_id)->count()
            : $this->participants->count();
    }

    /** @return iterable<array{0: string, 1: float}> day and amount of every cost */
    private function costEvents(): iterable
    {
        foreach ($this->expenses as $e) {
            if (! $e->isPerPerson()) {
                $day = $e->spent_on->toDateString();
                if ($this->inRange($day)) {
                    yield [$day, $e->fixedAmount()];
                }

                continue;
            }

            $days = $e->evr_activity_id ? $this->seats->where('activity', (int) $e->evr_activity_id)->pluck('day') : $this->participants;
            foreach ($days as $day) {
                yield [$day, (float) $e->unit_amount];
            }
        }
    }

    /** A seat with a price that was given away. Seats of free activities are not courtesies. */
    private static function isCourtesy(object $seat): bool
    {
        return $seat->total <= 0 && $seat->price > 0;
    }

    private function day(mixed $value): ?string
    {
        return $value ? Carbon::parse($value, 'UTC')->setTimezone(self::TIMEZONE)->toDateString() : null;
    }

    private function inRange(?string $day): bool
    {
        if ($this->from === null && $this->to === null) {
            return true;
        }

        return $day !== null && ($this->from === null || $day >= $this->from) && ($this->to === null || $day <= $this->to);
    }
}
