<?php

namespace App\Jobs;

use App\Mail\SubscriptionConfirmationMail;
use App\Models\Contact;
use App\Models\MailSetting;
use App\Services\Mail\CrmMailer;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class SendSubscriptionConfirmation implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public array $backoff = [60, 300];

    public function __construct(public Contact $contact) {}

    public function handle(CrmMailer $mailer): void
    {
        $mailer->for(MailSetting::PROFILE_TRANSACTIONAL)
            ->to($this->contact->email)
            ->send(new SubscriptionConfirmationMail($this->contact));
    }
}
