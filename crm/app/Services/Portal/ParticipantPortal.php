<?php

namespace App\Services\Portal;

use App\Models\Registration;
use App\Models\RegistrationActivity;
use App\Models\RegistrationParticipant;
use App\Services\CheckIn\ParticipantRecord;
use App\Services\Passes\CalendarFeed;
use App\Services\Passes\ParticipantSchedule;
use App\Services\Passes\PassLinks;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

/**
 * "Inscritos": a participant types their document number and sees their own paid registration. Read only,
 * and without the check-in code or contact details, since anyone who knows a document number gets this far.
 */
class ParticipantPortal
{
    /** Session key holding the document last looked up. */
    public const SESSION_KEY = 'portal_document';

    public function __construct(private ParticipantSchedule $schedule, private CalendarFeed $calendar) {}

    public static function normalize(string $document): string
    {
        return Str::upper(preg_replace('/[\s.\-]/', '', $document));
    }

    /**
     * One entry per person with this document in paid orders (usually one; two if the same number was
     * registered under different document types).
     *
     * @return list<array<string, mixed>>
     */
    public function lookup(string $document): array
    {
        $document = self::normalize($document);
        if (strlen($document) < 4) {
            return [];
        }

        return RegistrationParticipant::query()
            ->whereHas('registration', fn (Builder $r) => $r->whereIn('status', Registration::PAID_STATUSES))
            ->whereRaw("UPPER(REPLACE(REPLACE(REPLACE(COALESCE(document_number, ''), '.', ''), '-', ''), ' ', '')) = ?", [$document])
            ->with(['registration', 'activities.checkIn'])
            ->get()
            ->sortByDesc(fn (RegistrationParticipant $p) => $p->registration->ordered_at?->getTimestamp() ?? $p->id)
            ->groupBy(fn (RegistrationParticipant $p) => $p->evr_participant_id > 0 ? "p{$p->evr_participant_id}" : 'd'.strtolower((string) $p->document_type))
            ->map(fn (Collection $rows) => $this->person($rows))
            ->values()
            ->all();
    }

    /**
     * @param  Collection<int, RegistrationParticipant>  $rows  Newest order first.
     * @return array<string, mixed>
     */
    private function person(Collection $rows): array
    {
        $latest = $rows->first();
        $active = $rows->flatMap(fn (RegistrationParticipant $p) => $p->activities)
            ->filter(fn (RegistrationActivity $a) => in_array($a->status, RegistrationActivity::ACTIVE_STATUSES, true));
        $details = config('crm.wallet.event_name').'. Presenta tu código QR en el ingreso.';
        $google = $this->schedule->entries($active)->mapWithKeys(fn (array $e) => [$e['id'] => $this->calendar->googleLink($e, $details)]);
        $feed = PassLinks::calendar($latest);

        $activities = $active
            ->sortBy(fn (RegistrationActivity $a) => ($a->event_date?->toDateString() ?? '9999').' '.$a->start_time)
            ->map(fn (RegistrationActivity $a) => [
                'id' => $a->id,
                'name' => $a->name,
                'type' => $a->type,
                'date' => $a->event_date?->toDateString(),
                'start_time' => $a->start_time ? substr($a->start_time, 0, 5) : null,
                'checked_in' => $a->checkIn !== null,
                'google_calendar' => $google->get($a->id),
            ])
            ->values();

        return [
            'key' => $latest->evr_participant_id > 0 ? "p{$latest->evr_participant_id}" : "r{$latest->id}",
            'name' => $latest->full_name,
            'document' => trim((ParticipantRecord::DOCUMENT_TYPES[strtolower((string) $latest->document_type)] ?? $latest->document_type).' '.self::maskDocument($latest->document_number)),
            'email' => self::maskEmail($latest->email ?: ($latest->registration->billing['email'] ?? null)),
            'entity' => $rows->pluck('entity')->filter()->first(),
            'shirt_size' => $latest->shirt_size,
            'meal_type' => $latest->meal_type,
            'qr_sent' => $rows->pluck('qr_sent_at')->filter()->isNotEmpty(),
            'orders' => $rows->map(fn (RegistrationParticipant $p) => $p->registration->number)->unique()->values(),
            'activities' => $activities,
            'calendar' => ['ics' => $feed, 'google' => $this->calendar->googleSubscribeLink($feed)],
            'days' => $this->days($activities->pluck('date')->filter()->unique()->sort()->values()),
        ];
    }

    /**
     * The days between the person's first and last activity, so the calendar strip has no holes.
     *
     * @param  Collection<int, string>  $dates
     * @return list<string>
     */
    private function days(Collection $dates): array
    {
        if ($dates->isEmpty()) {
            return [];
        }

        $first = CarbonImmutable::parse($dates->first());
        $last = CarbonImmutable::parse($dates->last());
        if ($first->diffInDays($last) > 14) {
            return $dates->all();
        }

        $days = [];
        for ($d = $first; $d->lte($last); $d = $d->addDay()) {
            $days[] = $d->toDateString();
        }

        return $days;
    }

    /** Only the last four digits. */
    private static function maskDocument(?string $number): string
    {
        $number = (string) $number;

        return strlen($number) > 4 ? str_repeat('•', 4).substr($number, -4) : $number;
    }

    /** a•••@example.com: enough to recognise where the QR went, not enough to copy it. */
    private static function maskEmail(?string $email): ?string
    {
        if (! $email || ! str_contains($email, '@')) {
            return null;
        }
        [$user, $domain] = explode('@', Str::lower($email), 2);

        return Str::substr($user, 0, 1).'•••@'.$domain;
    }
}
