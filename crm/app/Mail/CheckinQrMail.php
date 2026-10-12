<?php

namespace App\Mail;

use App\Models\RegistrationActivity;
use App\Models\RegistrationParticipant;
use App\Services\CheckIn\CheckinQr;
use App\Services\CheckIn\ParticipantRecord;
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
        $participant = $this->participant;

        // Every activity of the person in paid orders, not only the ones bought with this code.
        $activities = ParticipantRecord::rows($participant)
            ->filter(fn (RegistrationParticipant $p) => $p->registration->isPaid())
            ->flatMap(fn (RegistrationParticipant $p) => $p->activities)
            ->filter(fn (RegistrationActivity $a) => in_array($a->status, RegistrationActivity::ACTIVE_STATUSES, true))
            ->sortBy(fn (RegistrationActivity $a) => $a->event_date?->toDateString().' '.$a->start_time)
            ->values();

        return new Content(
            markdown: 'mail.checkin-qr',
            with: [
                'name' => $participant->full_name,
                'code' => $participant->checkin_code,
                'png' => app(CheckinQr::class)->png($participant, 8),
                'activities' => $activities,
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
