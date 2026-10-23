<?php

namespace App\Mail;

use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

class PasswordResetMail extends Mailable
{
    public function __construct(public string $name, public string $url, public int $minutes) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: 'Restablece tu contraseña del CRM');
    }

    public function content(): Content
    {
        return new Content(
            markdown: 'mail.password-reset',
            with: ['name' => $this->name, 'url' => $this->url, 'minutes' => $this->minutes],
        );
    }
}
