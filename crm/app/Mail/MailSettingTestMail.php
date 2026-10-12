<?php

namespace App\Mail;

use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

class MailSettingTestMail extends Mailable
{
    public function __construct(public string $profile, public string $provider) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: 'Prueba de correo · CRM 360 Colombia Birdfair');
    }

    public function content(): Content
    {
        return new Content(text: 'mail.test-setting');
    }
}
