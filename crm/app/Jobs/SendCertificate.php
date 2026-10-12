<?php

namespace App\Jobs;

use App\Mail\CertificateMail;
use App\Models\CertificateRecipient;
use App\Models\MailSetting;
use App\Services\Mail\CrmMailer;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Str;
use Throwable;

class SendCertificate implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public array $backoff = [60, 300];

    /**
     * @param  bool  $resend  false skips people who already got it, so pressing Enviar twice mails nobody twice.
     */
    public function __construct(public CertificateRecipient $recipient, public bool $resend = false) {}

    public function handle(CrmMailer $mailer): void
    {
        $recipient = $this->recipient;

        if (! $recipient->email || ($recipient->sent_at && ! $this->resend)) {
            return;
        }

        $mailer->for(MailSetting::PROFILE_TRANSACTIONAL)
            ->to($recipient->email, $recipient->full_name)
            ->send(new CertificateMail($recipient));

        $recipient->update(['sent_at' => now(), 'failed_at' => null, 'error' => null]);
    }

    public function failed(?Throwable $e): void
    {
        $this->recipient->update([
            'failed_at' => now(),
            'queued_at' => null,
            'error' => Str::limit((string) $e?->getMessage(), 480),
        ]);
    }
}
