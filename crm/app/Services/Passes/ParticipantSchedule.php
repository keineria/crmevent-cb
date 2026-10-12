<?php

namespace App\Services\Passes;

use App\Models\EventActivity;
use App\Models\RegistrationActivity;
use App\Models\RegistrationParticipant;
use App\Services\CheckIn\ParticipantRecord;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * A participant's activities in paid orders, with start, end and place, for calendars and wallet passes.
 * The same person may have bought in several orders; all of them count.
 */
class ParticipantSchedule
{
    public const TIMEZONE = 'America/Bogota';

    /** Length used when the plugin has no end time for an activity. */
    private const DEFAULT_HOURS = 2;

    /**
     * @return Collection<int, array{id: int, name: string, type: string|null, date: string, all_day: bool, start: CarbonImmutable, end: CarbonImmutable, location: string|null}>
     */
    public function for(RegistrationParticipant $participant): Collection
    {
        $activities = ParticipantRecord::rows($participant)
            ->filter(fn (RegistrationParticipant $p) => $p->registration->isPaid())
            ->flatMap(fn (RegistrationParticipant $p) => $p->activities)
            ->filter(fn (RegistrationActivity $a) => $a->event_date && in_array($a->status, RegistrationActivity::ACTIVE_STATUSES, true));

        return $this->entries($activities);
    }

    /**
     * @param  Collection<int, RegistrationActivity>  $activities
     * @return Collection<int, array{id: int, name: string, type: string|null, date: string, all_day: bool, start: CarbonImmutable, end: CarbonImmutable, location: string|null}>
     */
    public function entries(Collection $activities): Collection
    {
        $catalog = EventActivity::query()
            ->whereIn('evr_id', $activities->pluck('evr_activity_id')->unique())
            ->get(['evr_id', 'destination', 'end_time'])
            ->keyBy('evr_id');

        return $activities
            ->filter(fn (RegistrationActivity $a) => $a->event_date !== null)
            ->map(function (RegistrationActivity $a) use ($catalog) {
                $date = $a->event_date->toDateString();
                $info = $catalog->get($a->evr_activity_id);

                if (! $a->start_time) {
                    $start = CarbonImmutable::parse($date, self::TIMEZONE);

                    return $this->entry($a, $date, true, $start, $start->addDay(), $info?->destination);
                }

                $start = CarbonImmutable::parse("{$date} {$a->start_time}", self::TIMEZONE);
                $end = $info?->end_time ? CarbonImmutable::parse("{$date} {$info->end_time}", self::TIMEZONE) : null;
                if (! $end || $end->lte($start)) {
                    $end = $start->addHours(self::DEFAULT_HOURS);
                }

                return $this->entry($a, $date, false, $start, $end, $info?->destination);
            })
            ->sortBy(fn (array $e) => $e['start']->getTimestamp())
            ->values();
    }

    /**
     * @return array{id: int, name: string, type: string|null, date: string, all_day: bool, start: CarbonImmutable, end: CarbonImmutable, location: string|null}
     */
    private function entry(RegistrationActivity $a, string $date, bool $allDay, CarbonImmutable $start, CarbonImmutable $end, ?string $location): array
    {
        return [
            'id' => $a->id,
            'name' => (string) $a->name,
            'type' => $a->type,
            'date' => $date,
            'all_day' => $allDay,
            'start' => $start,
            'end' => $end,
            'location' => $location ?: null,
        ];
    }
}
