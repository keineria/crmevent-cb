<?php

namespace App\Jobs;

use App\Mail\SurveyMail;
use App\Models\MailSetting;
use App\Models\SurveyInvitation;
use App\Services\Mail\CrmMailer;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Str;
use Throwable;

class SendSurvey implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public array $backoff = [60, 300];

    /**
     * @param  bool  $resend  false skips people who already got it or answered, so nobody is mailed twice by accident.
     */
    public function __construct(public SurveyInvitation $invitation, public bool $resend = false) {}

    public function handle(CrmMailer $mailer): void
    {
        $invitation = $this->invitation;

        if (! $invitation->email || (! $this->resend && ($invitation->sent_at || $invitation->responded_at))) {
            return;
        }

        $mailer->for(MailSetting::PROFILE_TRANSACTIONAL)
            ->to($invitation->email, $invitation->full_name)
            ->send(new SurveyMail($invitation));

        $invitation->update(['sent_at' => now(), 'failed_at' => null, 'error' => null]);
    }

    public function failed(?Throwable $e): void
    {
        $this->invitation->update([
            'failed_at' => now(),
            'queued_at' => null,
            'error' => Str::limit((string) $e?->getMessage(), 480),
        ]);
    }
}
