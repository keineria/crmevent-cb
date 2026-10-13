<?php

namespace App\Mail;

use App\Models\Certificate;
use App\Models\CertificateCategory;
use App\Models\CertificateRecipient;
use App\Models\MailTemplate;
use App\Services\Campaigns\CampaignRenderer;
use App\Services\Certificates\CertificatePdf;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Attachment;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Support\Str;

/**
 * The email that carries one person's attendance certificate as a PDF. Its wording comes from the
 * "Correo del certificado" template, edited in Certificados › Correo.
 *
 * Template fields: {{nombre}}, {{nombre_completo}}, {{actividad}}, {{categoria}}, {{fecha}}, {{codigo}}, {{enlace}}.
 * Block, on its own line: {{certificado}} (a button to the verification page, where the PDF can be downloaded again).
 */
class CertificateMail extends Mailable
{
    public const FIELDS = [
        'nombre' => 'Nombre',
        'nombre_completo' => 'Nombre completo',
        'actividad' => 'Actividad',
        'categoria' => 'Categoría',
        'fecha' => 'Fecha de la actividad',
        'codigo' => 'Código del certificado',
        'enlace' => 'Enlace de verificación',
        'certificado' => 'Botón al certificado (bloque)',
    ];

    /** Certificate design and activity used by previews and test sends. */
    private ?CertificateCategory $sampleCategory = null;

    private ?Certificate $sampleCertificate = null;

    /**
     * @param  array{subject?: string|null, preheader?: string|null, body?: string|null, body_format?: string|null}|null  $draft  Unsaved content, for previews.
     */
    public function __construct(public ?CertificateRecipient $recipient, public ?array $draft = null) {}

    /**
     * Made-up person for previews and test sends, so they never show real data. With a certificate, its real
     * design and activity are used, so a test shows exactly what people will get.
     */
    public static function sample(?Certificate $certificate = null, ?array $draft = null): self
    {
        $mail = new self(null, $draft);
        $mail->sampleCertificate = $certificate;
        $mail->sampleCategory = $certificate?->category ?? new CertificateCategory([
            ...CertificateCategory::DEFAULTS, 'name' => 'Conferencias', 'activity_type' => 'conference',
        ]);

        return $mail;
    }

    public function envelope(): Envelope
    {
        return new Envelope(subject: $this->renderedSubject());
    }

    public function renderedSubject(): string
    {
        $subject = $this->part('subject') ?: 'Tu certificado de asistencia';

        return trim(preg_replace('/\s+/', ' ', app(CampaignRenderer::class)->replace($subject, $this->values(), escape: false)));
    }

    public function content(): Content
    {
        $renderer = app(CampaignRenderer::class);
        $preheader = $this->part('preheader');

        $body = $this->bodyHtml();

        return new Content(
            view: CampaignRenderer::isDocument($body) ? 'mail.standalone' : 'mail.certificate',
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
        $button = '[[Ver mi certificado|'.$this->values()['enlace'].']]';
        $body = preg_replace('/\{\{\s*certificado\s*\}\}/u', "\n\n{$button}\n\n", $body);

        return $renderer->replace($renderer->html($body, format: $this->format()), $this->values(), escape: true);
    }

    /**
     * @return list<Attachment>
     */
    public function attachments(): array
    {
        $values = $this->values();

        return [
            Attachment::fromData(fn () => $this->pdf(), 'Certificado-'.Str::slug($values['nombre_completo']).'.pdf')
                ->withMime('application/pdf'),
        ];
    }

    public function pdf(): string
    {
        $pdf = app(CertificatePdf::class);

        return $this->recipient ? $pdf->forRecipient($this->recipient) : $pdf->sample($this->sampleCategory, $this->sampleCertificate);
    }

    /**
     * @return array<string, string|null>
     */
    private function values(): array
    {
        return once(function () {
            if (! $this->recipient) {
                $pdf = app(CertificatePdf::class);
                $values = $this->sampleCertificate
                    ? $pdf->values($this->sampleCertificate, CertificatePdf::SAMPLE['nombre'], null)
                    : ['actividad' => 'Conferencias Colombia Birdfair', 'categoria' => 'Conferencias', 'fecha' => '30 de septiembre de 2027'];

                return [
                    'nombre' => CertificatePdf::SAMPLE['nombre'],
                    'nombre_completo' => CertificatePdf::SAMPLE['nombre'],
                    'actividad' => $values['actividad'],
                    'categoria' => $values['categoria'],
                    'fecha' => $values['fecha'],
                    'codigo' => 'CERT-MUESTRA00',
                    'enlace' => route('certificates.verify', 'CERT-MUESTRA00'),
                ];
            }

            $certificate = $this->recipient->certificate;

            return [
                'nombre' => $this->recipient->full_name,
                'nombre_completo' => $this->recipient->full_name,
                'actividad' => $certificate->activity_name,
                'categoria' => $certificate->category->name,
                'fecha' => $certificate->activity_date ? app(CertificatePdf::class)->date($certificate->activity_date) : '',
                'codigo' => $this->recipient->code,
                'enlace' => $this->recipient->verificationUrl(),
            ];
        });
    }

    private function format(): string
    {
        return $this->part('body_format') ?: CampaignRenderer::FORMAT_MARKDOWN;
    }

    private function part(string $part): ?string
    {
        return $this->draft !== null ? ($this->draft[$part] ?? null) : MailTemplate::system(MailTemplate::CERTIFICATE)->{$part};
    }
}
