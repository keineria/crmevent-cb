<?php

namespace App\Services\Mail;

use App\Mail\MailSettingTestMail;
use App\Models\MailSetting;

/**
 * Sends a real test message with a profile's current (unverified) values.
 * Bound in the container so tests can swap it for a fake.
 */
class MailSettingTester
{
    public function __construct(private CrmMailer $mailer) {}

    /**
     * @throws \Throwable when the provider rejects the connection or the message.
     */
    public function send(MailSetting $setting, string $to): void
    {
        $this->mailer->build($setting->transportConfig())
            ->to($to)
            ->send(new MailSettingTestMail($setting->profile, $setting->provider));
    }
}
