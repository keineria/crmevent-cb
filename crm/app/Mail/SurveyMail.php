<?php

namespace App\Mail;

use App\Models\MailTemplate;
use App\Models\SurveyInvitation;
use App\Services\Campaigns\CampaignRenderer;
use App\Services\Certificates\CertificatePdf;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/**
 * The email that invites one person to answer the survey of an activity they attended. Its wording comes from the
 * "Correo de la encuesta" template, edited in Encuestas › Correo.
 *
 * Template fields: {{nombre}}, {{nombre_completo}}, {{actividad}}, {{fecha}}, {{enlace}}.
 * Block, on its own line: {{encuesta}} (a button to the person's survey).
 */
class SurveyMail extends Mailable
{
    public const FIELDS = [
        'nombre' => 'Nombre',
        'nombre_completo' => 'Nombre completo',
        'actividad' => 'Actividad',
        'fecha' => 'Fecha de la actividad',
        'enlace' => 'Enlace a la encuesta',
        'encuesta' => 'Botón a la encuesta (bloque)',
    ];

    /**
     * @param  array{subject?: string|null, preheader?: string|null, body?: string|null, body_format?: string|null}|null  $draft  Unsaved content, for previews.
     */
    public function __construct(public ?SurveyInvitation $invitation, public ?array $draft = null) {}

    /** Made-up person and activity for previews and test sends, so they never show real data. */
    public static function sample(?array $draft = null): self
    {
        return new self(null, $draft);
    }

    public function envelope(): Envelope
    {
        return new Envelope(subject: $this->renderedSubject());
    }

    public function renderedSubject(): string
    {
        $subject = $this->part('subject') ?: '¿Cómo te fue en {{actividad}}?';

        return trim(preg_replace('/\s+/', ' ', app(CampaignRenderer::class)->replace($subject, $this->values(), escape: false)));
    }

    public function content(): Content
    {
        $renderer = app(CampaignRenderer::class);
        $preheader = $this->part('preheader');

        $body = $this->bodyHtml();

        return new Content(
            view: CampaignRenderer::isDocument($body) ? 'mail.standalone' : 'mail.survey',
            with: [
                'subject' => $this->renderedSubject(),
                'preheader' => $preheader ? $renderer->replace($preheader, $this->values(), escape: false) : null,
                'body' => $body,
            ],
        );
    }

    public function bodyHtml(): string
    {
        $renderer = app(CampaignRenderer::class);
        $body = (string) $this->part('body');
        $button = '[[Responder la encuesta|'.$this->values()['enlace'].']]';
        $body = preg_replace('/\{\{\s*encuesta\s*\}\}/u', "\n\n{$button}\n\n", $body);

        return $renderer->replace($renderer->html($body, format: $this->format()), $this->values(), escape: true);
    }

    /**
     * @return array<string, string|null>
     */
    private function values(): array
    {
        return once(function () {
            if (! $this->invitation) {
                return [
                    'nombre' => CertificatePdf::SAMPLE['nombre'],
                    'nombre_completo' => CertificatePdf::SAMPLE['nombre'],
                    'actividad' => 'Salida Reserva Río Blanco',
                    'fecha' => '2 de octubre de 2027',
                    'enlace' => route('surveys.public.show', 'muestra'),
                ];
            }

            $survey = $this->invitation->survey;

            return [
                'nombre' => $this->invitation->full_name,
                'nombre_completo' => $this->invitation->full_name,
                'actividad' => $survey->activity_name,
                'fecha' => $survey->activity_date ? app(CertificatePdf::class)->date($survey->activity_date) : '',
                'enlace' => $this->invitation->url(),
            ];
        });
    }

    private function format(): string
    {
        return $this->part('body_format') ?: CampaignRenderer::FORMAT_MARKDOWN;
    }

    private function part(string $part): ?string
    {
        return $this->draft !== null ? ($this->draft[$part] ?? null) : MailTemplate::system(MailTemplate::SURVEY)->{$part};
    }
}
