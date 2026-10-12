<?php

namespace App\Services\Mail;

use App\Mail\BroadcastMailable;
use App\Models\Contact;
use App\Models\MailSetting;

/**
 * The only way broadcasts leave the CRM: it refuses contacts without consent or who unsubscribed.
 */
class BroadcastSender
{
    public function __construct(private CrmMailer $mailer) {}

    /**
     * @return bool false when the contact may not receive broadcasts
     */
    public function send(BroadcastMailable $mail): bool
    {
        $contact = $mail->contact;

        if (! Contact::mailable()->whereKey($contact->getKey())->exists()) {
            return false;
        }

        $this->mailer->for(MailSetting::PROFILE_BROADCAST)->to($contact->email)->send($mail);

        return true;
    }
}
