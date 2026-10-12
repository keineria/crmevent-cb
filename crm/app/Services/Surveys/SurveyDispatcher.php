<?php

namespace App\Services\Surveys;

use App\Jobs\SendSurvey;
use App\Models\EventActivity;
use App\Models\MailSetting;
use App\Models\Survey;
use App\Models\SurveyForm;
use App\Models\SurveyInvitation;
use App\Models\User;
use App\Services\Certificates\CertificateAudience;
use Illuminate\Support\Collection;

/**
 * Opens the survey of each activity when it ends and mails it to the people who were there, paced under the
 * transactional mailbox's hourly limit.
 */
class SurveyDispatcher
{
    /** An activity whose send time passed longer ago than this is not surveyed on its own (only with Enviar ahora). */
    public const MAX_LATE_HOURS = 72;

    public function __construct(private CertificateAudience $audience) {}

    /**
     * Runs from the scheduler: opens the surveys that are due and mails whoever has not got theirs yet.
     *
     * @return int Emails queued.
     */
    public function tick(): int
    {
        if (! self::mailReady()) {
            return 0;
        }

        $forms = SurveyForm::where('enabled', true)->get()->keyBy('activity_type');
        if ($forms->isNotEmpty()) {
            $taken = Survey::pluck('evr_activity_id')->all();
            EventActivity::whereIn('type', $forms->keys())
                ->where('status', '!=', 'archived')
                ->whereNotIn('evr_id', $taken)
                ->whereBetween('event_date', [now()->subDays(4)->toDateString(), now()->addDay()->toDateString()])
                ->get()
                ->each(function (EventActivity $activity) use ($forms) {
                    $form = $forms[$activity->type];
                    $end = SurveyForm::activityEnd($activity);
                    $sendsAt = $form->sendsAt($activity);
                    if (! $end || ! $sendsAt || $sendsAt->isFuture() || $sendsAt->lt(now()->subHours(self::MAX_LATE_HOURS))) {
                        return;
                    }
                    // Turning a form on never mails activities that had already ended.
                    if ($form->enabled_at && $end->lt($form->enabled_at)) {
                        return;
                    }
                    $this->open($activity, $form, Survey::SOURCE_AUTO);
                });
        }

        $queued = 0;
        Survey::where('created_at', '>=', now()->subHours(Survey::COLLECT_HOURS))->get()
            ->each(function (Survey $survey) use (&$queued) {
                $queued += $this->send($survey);
            });

        return $queued;
    }

    public function open(EventActivity $activity, SurveyForm $form, string $source, ?User $by = null): Survey
    {
        return Survey::firstOrCreate(['evr_activity_id' => $activity->evr_id], [
            'activity_type' => $activity->type,
            'activity_name' => $activity->name,
            'activity_date' => $activity->event_date,
            'title' => $form->title,
            'intro' => $form->intro,
            'questions' => $form->questions,
            'require_check_in' => $form->require_check_in,
            'source' => $source,
            'created_by' => $by?->id,
        ]);
    }

    /**
     * Who the survey is for: one invitation per person who qualifies. Unsent invitations of people who no longer
     * qualify are dropped; sent ones stay with their answers.
     *
     * @return Collection<int, SurveyInvitation>
     */
    public function sync(Survey $survey): Collection
    {
        $people = $this->audience->forActivity($survey->evr_activity_id, $survey->require_check_in)
            ->where('eligible', true)->keyBy('person_key');
        $existing = $survey->invitations()->get()->keyBy('person_key');

        $survey->invitations()->whereNull('sent_at')->whereNull('responded_at')
            ->whereNotIn('person_key', $people->keys()->all())->delete();

        return $people->map(function (array $person, string $key) use ($survey, $existing) {
            $invitation = $existing->get($key) ?? new SurveyInvitation(['survey_id' => $survey->id, 'person_key' => $key]);
            if (! $invitation->sent_at) {
                $invitation->fill([
                    'registration_participant_id' => $person['participant']->id,
                    'full_name' => $person['participant']->full_name,
                    'email' => $person['email'],
                ])->save();
            }

            return $invitation;
        })->values();
    }

    /**
     * Queues the survey for everyone who qualifies and has not got it, after whatever is already waiting in line.
     *
     * @return int Emails queued.
     */
    public function send(Survey $survey): int
    {
        $perHour = max(1, (int) config('crm.survey_emails_per_hour'));
        // Surveys of several activities can end at once: they share one line, so the mailbox limit holds.
        $waiting = SurveyInvitation::whereNull('sent_at')->whereNull('failed_at')->where('queued_at', '>', now()->subDay())->count();
        $count = 0;

        foreach ($this->sync($survey) as $invitation) {
            if ($invitation->sent_at || $invitation->responded_at || $invitation->isQueued() || $invitation->failed_at || ! $invitation->email) {
                continue;
            }
            SendSurvey::dispatch($invitation)->delay(now()->addSeconds(intdiv(($waiting + $count) * 3600, $perHour)));
            $invitation->update(['queued_at' => now(), 'error' => null]);
            $count++;
        }

        return $count;
    }

    public static function mailReady(): bool
    {
        return (bool) MailSetting::where('profile', MailSetting::PROFILE_TRANSACTIONAL)->first()?->isActive();
    }
}
