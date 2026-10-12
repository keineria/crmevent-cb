<?php

namespace App\Mail;

use App\Models\CampaignRecipient;
use App\Models\Contact;
use App\Models\MailSetting;
use App\Services\Campaigns\CampaignRenderer;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Mail\Mailables\Headers;

/**
 * A campaign as one contact receives it. With a recipient it adds open and click tracking
 * when they are turned on in Correo de envíos › Difusión.
 */
class CampaignMail extends BroadcastMailable
{
    public function __construct(
        Contact $contact,
        public string $campaignSubject,
        public ?string $preheader,
        public string $body,
        public ?CampaignRecipient $recipient = null,
    ) {
        parent::__construct($contact);
    }

    public function envelope(): Envelope
    {
        return new Envelope(subject: app(CampaignRenderer::class)->subject($this->campaignSubject, $this->contact));
    }

    public function headers(): Headers
    {
        $headers = parent::headers();

        if ($this->recipient) {
            $headers->text['X-CRM-Campaign'] = (string) $this->recipient->campaign_id;
        }

        return $headers;
    }

    public function content(): Content
    {
        return new Content(htmlString: $this->renderHtml());
    }

    public function renderHtml(): string
    {
        $renderer = app(CampaignRenderer::class);
        $setting = MailSetting::where('profile', MailSetting::PROFILE_BROADCAST)->first();
        $recipient = $this->recipient;

        $trackLink = $recipient && ($setting->track_clicks ?? false)
            ? fn (string $url) => route('campaigns.track.click', ['token' => $recipient->token, 'u' => $url, 's' => self::linkSignature($recipient->token, $url)])
            : null;

        return view('mail.campaign', [
            'subject' => $renderer->subject($this->campaignSubject, $this->contact),
            'preheader' => $this->preheader ? $renderer->fill($this->preheader, $this->contact, escape: false) : null,
            'body' => $renderer->body($this->body, $this->contact, $trackLink),
            'pixel' => $recipient && ($setting->track_opens ?? false) ? route('campaigns.track.open', $recipient->token) : null,
            'unsubscribeUrl' => $this->unsubscribeUrl,
            'footerText' => $this->footerText,
        ])->render();
    }

    /**
     * Keeps the click redirect from being used to send people to any other site.
     */
    public static function linkSignature(string $token, string $url): string
    {
        return substr(hash_hmac('sha256', "{$token}|{$url}", (string) config('app.key')), 0, 20);
    }
}
