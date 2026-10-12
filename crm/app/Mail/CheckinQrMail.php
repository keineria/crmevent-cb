<?php

namespace App\Mail;

use App\Models\RegistrationParticipant;
use App\Services\CheckIn\CheckinQr;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Attachment;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

class CheckinQrMail extends Mailable
{
    public function __construct(public RegistrationParticipant $participant) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: "Tu código de ingreso a Colombia Birdfair · {$this->participant->full_name}");
    }

    public function content(): Content
    {
        $participant = $this->participant->loadMissing('activities');

        return new Content(
            markdown: 'mail.checkin-qr',
            with: [
                'name' => $participant->full_name,
                'code' => $participant->checkin_code,
                'png' => app(CheckinQr::class)->png($participant, 8),
                'activities' => $participant->activities->whereIn('status', ['reserved', 'confirmed'])->values(),
            ],
        );
    }

    /**
     * Also attached, so it can be saved to the phone and shown without signal.
     *
     * @return list<Attachment>
     */
    public function attachments(): array
    {
        return [
            Attachment::fromData(fn () => app(CheckinQr::class)->png($this->participant), "QR-{$this->participant->checkin_code}.png")
                ->withMime('image/png'),
        ];
    }
}
