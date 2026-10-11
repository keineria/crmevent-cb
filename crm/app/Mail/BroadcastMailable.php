<?php

namespace App\Mail;

use App\Models\Contact;
use App\Models\MailSetting;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Headers;

/**
 * Base for every newsletter or broadcast. It always adds the unsubscribe link and the legal footer
 * configured in Correo de envíos › Difusión, plus the List-Unsubscribe headers that let Gmail and
 * Outlook show their own "Cancelar suscripción" button.
 *
 * Views render the footer with @include('mail.partials.broadcast-footer').
 */
abstract class BroadcastMailable extends Mailable
{
    public string $unsubscribeUrl;

    public ?string $footerText;

    public function __construct(public Contact $contact)
    {
        $this->unsubscribeUrl = $contact->unsubscribeUrl();
        $this->footerText = MailSetting::where('profile', MailSetting::PROFILE_BROADCAST)->value('footer_text');
    }

    public function headers(): Headers
    {
        return new Headers(text: [
            'List-Unsubscribe' => "<{$this->unsubscribeUrl}>",
            'List-Unsubscribe-Post' => 'List-Unsubscribe=One-Click',
        ]);
    }
}
