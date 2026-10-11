<?php

namespace App\Mail;

use App\Models\Contact;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Support\Facades\URL;

class SubscriptionConfirmationMail extends Mailable
{
    public function __construct(public Contact $contact) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: 'Confirma tu suscripción a Colombia Birdfair');
    }

    public function content(): Content
    {
        return new Content(
            markdown: 'mail.subscription-confirmation',
            with: [
                'name' => $this->contact->first_name,
                'url' => URL::temporarySignedRoute('subscription.confirm', now()->addDays(7), ['contact' => $this->contact->id]),
            ],
        );
    }
}
