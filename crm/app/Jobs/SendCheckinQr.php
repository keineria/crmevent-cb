<?php

namespace App\Jobs;

use App\Mail\CheckinQrMail;
use App\Models\MailSetting;
use App\Models\RegistrationParticipant;
use App\Services\CheckIn\ParticipantRecord;
use App\Services\Mail\CrmMailer;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class SendCheckinQr implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public array $backoff = [60, 300];

    /**
     * @param  bool  $resend  false skips people who already got it, so a repeated bulk send mails nobody twice.
     */
    public function __construct(public RegistrationParticipant $participant, public bool $resend = false) {}

    public function handle(CrmMailer $mailer): void
    {
        $email = $this->participant->qrEmail();

        if (! $email || ! $this->participant->registration->isPaid() || ($this->participant->qr_sent_at && ! $this->resend)) {
            return;
        }

        $mailer->for(MailSetting::PROFILE_TRANSACTIONAL)
            ->to($email, $this->participant->full_name)
            ->send(new CheckinQrMail($this->participant));

        ParticipantRecord::rows($this->participant)->toQuery()->update(['qr_sent_at' => now()]);
    }
}
