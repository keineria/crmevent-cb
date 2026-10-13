<?php

namespace App\Mail;

use App\Models\MailTemplate;
use App\Models\RegistrationActivity;
use App\Models\RegistrationParticipant;
use App\Services\Campaigns\CampaignRenderer;
use App\Services\CheckIn\CheckinQr;
use App\Services\CheckIn\ParticipantRecord;
use App\Services\Passes\AppleWalletPass;
use App\Services\Passes\CalendarFeed;
use App\Services\Passes\GoogleWalletPass;
use App\Services\Passes\PassLinks;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Attachment;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/**
 * The email that carries a participant's check-in QR. Its wording comes from the "Correo del código QR" template,
 * edited in Check-in › Correo del QR; the QR, the code and the activity list are filled in here.
 *
 * Template fields: {{nombre}}, {{nombre_completo}}, {{codigo}}, {{correo}}. Blocks, each on its own line: {{qr}}, {{actividades}}.
 */
class CheckinQrMail extends Mailable
{
    public const FIELDS = [
        'nombre' => 'Nombre',
        'nombre_completo' => 'Nombre completo',
        'codigo' => 'Código de ingreso',
        'correo' => 'Correo',
        'qr' => 'Código QR (bloque)',
        'actividades' => 'Lista de actividades (bloque)',
    ];

    /** @var list<array{name: string, when: string}>|null */
    private ?array $sampleActivities = null;

    /**
     * @param  array{subject?: string|null, preheader?: string|null, body?: string|null, body_format?: string|null}|null  $draft  Unsaved content, for previews.
     */
    public function __construct(public RegistrationParticipant $participant, public ?array $draft = null) {}

    /**
     * Made-up person for previews and test sends, so they never show real data.
     */
    public static function sample(?array $draft = null): self
    {
        $mail = new self(new RegistrationParticipant([
            'full_name' => 'María Pérez',
            'email' => 'maria@ejemplo.com',
            'checkin_code' => 'CBF-MPZ7K2QH',
        ]), $draft);
        $mail->sampleActivities = [
            ['name' => 'Conferencias', 'when' => 'jueves 30 de septiembre · 08:00'],
            ['name' => 'Salida a Laguna de Sonso', 'when' => 'viernes 1 de octubre · 05:00'],
        ];

        return $mail;
    }

    public function envelope(): Envelope
    {
        return new Envelope(subject: $this->renderedSubject());
    }

    public function renderedSubject(): string
    {
        $subject = $this->part('subject') ?: 'Tu código de ingreso a Colombia Birdfair';

        return trim(preg_replace('/\s+/', ' ', app(CampaignRenderer::class)->replace($subject, $this->values(), escape: false)));
    }

    public function content(): Content
    {
        $renderer = app(CampaignRenderer::class);
        $preheader = $this->part('preheader');

        $body = $this->bodyHtml();

        return new Content(
            view: CampaignRenderer::isDocument($body) ? 'mail.standalone' : 'mail.checkin-qr',
            with: [
                'subject' => $this->renderedSubject(),
                'preheader' => $preheader ? $renderer->replace($preheader, $this->values(), escape: false) : null,
                'body' => $body,
                'png' => app(CheckinQr::class)->png($this->participant, 8),
            ],
        );
    }

    /**
     * The body as HTML, with %%QR_SRC%% where the view puts the QR image.
     */
    public function bodyHtml(): string
    {
        $renderer = app(CampaignRenderer::class);
        $body = (string) $this->part('body');

        // Blocks go on their own paragraph whatever line they were typed on; without {{qr}} the QR still goes first.
        $body = preg_replace('/\{\{\s*(qr|actividades)\s*\}\}/u', "\n\n@@$1@@\n\n", $body);
        if (! str_contains($body, '@@qr@@')) {
            $body = "@@qr@@\n\n".$body;
        }

        $html = $renderer->replace($renderer->html($body, format: $this->format()), $this->values(), escape: true);

        return preg_replace_callback('/(?:<p>)?@@(qr|actividades)@@(?:<\/p>)?/', fn (array $m) => $m[1] === 'qr'
            ? $this->qrBlock()
            : $this->activitiesBlock(), $html);
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

    /**
     * @return array<string, string|null>
     */
    private function values(): array
    {
        return [
            'nombre' => $this->participant->full_name,
            'nombre_completo' => $this->participant->full_name,
            'codigo' => $this->participant->checkin_code,
            'correo' => $this->sampleActivities !== null ? $this->participant->email : $this->participant->qrEmail(),
        ];
    }

    /**
     * Subject, preheader or body: from the draft when previewing, else from the saved template.
     */
    private function format(): string
    {
        return $this->part('body_format') ?: CampaignRenderer::FORMAT_MARKDOWN;
    }

    private function part(string $part): ?string
    {
        return $this->draft !== null ? ($this->draft[$part] ?? null) : $this->template()->{$part};
    }

    private function template(): MailTemplate
    {
        return once(fn () => MailTemplate::system(MailTemplate::CHECKIN_QR));
    }

    private function qrBlock(): string
    {
        $code = e($this->participant->checkin_code);

        return '<div style="text-align:center;margin:24px 0;">'
            .'<img src="%%QR_SRC%%" alt="Código QR '.$code.'" width="240" height="240" style="width:240px;height:240px;">'
            .'<p style="font-size:20px;font-weight:bold;letter-spacing:2px;margin:8px 0 0;color:#1F2A1C;">'.$code.'</p>'
            .$this->buttons().'</div>';
    }

    /**
     * Wallet buttons (only for the wallets with keys set up) and calendar buttons, under the QR.
     */
    private function buttons(): string
    {
        $sample = $this->sampleActivities !== null;
        $p = $this->participant;
        $calendar = $sample ? '#' : PassLinks::calendar($p);

        $wallet = array_filter([
            'Agregar a Apple Wallet' => $sample ? (AppleWalletPass::configured() ? '#' : null) : PassLinks::apple($p),
            'Guardar en Google Wallet' => $sample ? (GoogleWalletPass::configured() ? '#' : null) : PassLinks::google($p),
        ]);
        $calendars = [
            'Agregar a Google Calendar' => $sample ? '#' : app(CalendarFeed::class)->googleSubscribeLink($calendar),
            'iPhone, Outlook y otros (.ics)' => $calendar,
        ];

        $row = fn (array $links, string $background, string $color) => '<p style="margin:12px 0 0;">'.implode(' ', array_map(
            fn (string $label, string $url) => '<a href="'.e($url).'" style="display:inline-block;margin:4px;padding:10px 16px;border-radius:8px;'
                ."background:{$background};color:{$color};font-size:14px;font-weight:600;text-decoration:none;\">".e($label).'</a>',
            array_keys($links), $links,
        )).'</p>';

        return ($wallet ? $row($wallet, '#1F2A1C', '#FFFFFF') : '')
            .$row($calendars, '#EEF3EC', '#2F5D2A');
    }

    private function activitiesBlock(): string
    {
        $activities = $this->activities();
        if ($activities === []) {
            return '';
        }

        $items = implode('', array_map(
            fn (array $a) => '<li>'.e($a['name']).($a['when'] !== '' ? ' · '.e($a['when']) : '').'</li>',
            $activities,
        ));

        return '<p><strong>Tus actividades</strong></p><ul>'.$items.'</ul>';
    }

    /**
     * Every activity of the person in paid orders, not only the ones bought with this code.
     *
     * @return list<array{name: string, when: string}>
     */
    private function activities(): array
    {
        if ($this->sampleActivities !== null) {
            return $this->sampleActivities;
        }

        return ParticipantRecord::rows($this->participant)
            ->filter(fn (RegistrationParticipant $p) => $p->registration->isPaid())
            ->flatMap(fn (RegistrationParticipant $p) => $p->activities)
            ->filter(fn (RegistrationActivity $a) => in_array($a->status, RegistrationActivity::ACTIVE_STATUSES, true))
            ->sortBy(fn (RegistrationActivity $a) => $a->event_date?->toDateString().' '.$a->start_time)
            ->map(fn (RegistrationActivity $a) => [
                'name' => $a->name,
                'when' => implode(' · ', array_filter([
                    $a->event_date ? $a->event_date->locale('es')->translatedFormat('l j \d\e F') : null,
                    $a->start_time ? substr($a->start_time, 0, 5) : null,
                ])),
            ])
            ->values()
            ->all();
    }
}
