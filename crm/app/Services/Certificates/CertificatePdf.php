<?php

namespace App\Services\Certificates;

use App\Models\Certificate;
use App\Models\CertificateCategory;
use App\Models\CertificateRecipient;
use chillerlan\QRCode\Common\EccLevel;
use chillerlan\QRCode\Output\QRGdImagePNG;
use chillerlan\QRCode\Output\QROutputInterface;
use chillerlan\QRCode\QRCode;
use chillerlan\QRCode\QROptions;
use Dompdf\Dompdf;
use Dompdf\Options;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Draws a certificate as an A4 landscape PDF from its category's design.
 */
class CertificatePdf
{
    /** Sample person for previews and test sends, so they never show real data. */
    public const SAMPLE = ['nombre' => 'María Pérez Gómez', 'documento' => '1.020.304.050'];

    public function forRecipient(CertificateRecipient $recipient): string
    {
        $certificate = $recipient->certificate;

        return $this->pdf($certificate->category, $this->values($certificate, $recipient->full_name, $recipient->document_number), $recipient->code);
    }

    /**
     * A certificate with the sample person: of a real activity when one is given, else of a made-up one.
     *
     * @param  array<string, mixed>|null  $draft  Unsaved design fields, for the live preview.
     */
    public function sample(CertificateCategory $category, ?Certificate $certificate = null, ?array $draft = null): string
    {
        if ($draft) {
            $category = (clone $category)->forceFill(array_intersect_key($draft, array_flip([
                'name', 'template', 'primary_color', 'accent_color', 'heading', 'intro', 'body', 'show_qr',
            ])));
            if (array_key_exists('signatures', $draft)) {
                $category->signatures = $draft['signatures'];
            }
        }

        $values = $certificate
            ? $this->values($certificate, self::SAMPLE['nombre'], self::SAMPLE['documento'])
            : [
                ...self::SAMPLE,
                'actividad' => $category->activity_type === 'workshop' ? 'Taller de fotografía de aves' : 'Conferencias Colombia Birdfair',
                'categoria' => (string) $category->name,
                'fecha' => $this->date(Carbon::parse('2027-09-30')),
                'edicion' => '2027',
                'horas' => '8',
            ];

        return $this->pdf($category, $values, 'CERT-MUESTRA00');
    }

    /**
     * @param  array<string, string>  $values
     */
    public function pdf(CertificateCategory $category, array $values, string $code): string
    {
        $options = new Options;
        $options->setChroot([resource_path('fonts/certificates')]);
        $options->setFontDir(storage_path('app/private/dompdf-fonts'));
        $options->setFontCache(storage_path('app/private/dompdf-fonts'));
        $options->setTempDir(sys_get_temp_dir());
        $options->setIsRemoteEnabled(false);
        $options->setDefaultPaperSize('a4');
        $options->setDefaultPaperOrientation('landscape');
        $options->setDpi(96);

        if (! is_dir(storage_path('app/private/dompdf-fonts'))) {
            mkdir(storage_path('app/private/dompdf-fonts'), 0775, true);
        }

        $dompdf = new Dompdf($options);
        $dompdf->loadHtml($this->html($category, $values, $code), 'UTF-8');
        $dompdf->setPaper('a4', 'landscape');
        $dompdf->render();

        return (string) $dompdf->output();
    }

    /**
     * @param  array<string, string>  $values
     */
    public function html(CertificateCategory $category, array $values, string $code): string
    {
        $template = array_key_exists($category->template, CertificateCategory::TEMPLATES) ? $category->template : 'selva';

        return view('certificates.pdf', [
            'template' => $template,
            'primary' => $this->color($category->primary_color, CertificateCategory::DEFAULTS['primary_color']),
            'accent' => $this->color($category->accent_color, CertificateCategory::DEFAULTS['accent_color']),
            'heading' => (string) $category->heading,
            'intro' => (string) $category->intro,
            'name' => $values['nombre'],
            'document' => $values['documento'] ?? null,
            'body' => $this->body((string) $category->body, $values),
            'edition' => $values['edicion'] ?? '',
            'logo' => $this->image($category->logo_path),
            'background' => $this->image($category->background_path),
            'signers' => array_map(fn (array $s) => [...$s, 'image' => $this->image($s['image_path'])], $category->signers()),
            'qr' => $category->show_qr ? $this->qr(route('certificates.verify', $code)) : null,
            'code' => $code,
            'verifyHost' => parse_url(url('/'), PHP_URL_HOST),
            'fonts' => resource_path('fonts/certificates'),
        ])->render();
    }

    /**
     * @return array<string, string>
     */
    public function values(Certificate $certificate, string $name, ?string $document): array
    {
        $date = $certificate->activity_date;

        return [
            'nombre' => $name,
            'documento' => (string) $document,
            'actividad' => $certificate->activity_name,
            'categoria' => (string) $certificate->category->name,
            'fecha' => $date ? $this->date($date) : '',
            'edicion' => $date ? (string) $date->year : (string) now()->year,
            'horas' => $certificate->hours !== null ? rtrim(rtrim(number_format((float) $certificate->hours, 1, ',', ''), '0'), ',') : '',
        ];
    }

    public function date(Carbon $date): string
    {
        return $date->locale('es')->translatedFormat('j \d\e F \d\e Y');
    }

    /**
     * The certificate text as HTML: values escaped, the activity in bold, and a clause whose value is empty
     * (", con una intensidad de {{horas}} horas" without hours) dropped instead of printed half empty.
     *
     * @param  array<string, string>  $values
     */
    public function body(string $text, array $values): string
    {
        foreach ($values as $key => $value) {
            if ($value === '') {
                $text = preg_replace('/,[^,.]*\{\{\s*'.$key.'\s*\}\}[^,.]*/u', '', $text) ?? $text;
            }
        }

        $html = e($text);

        return preg_replace_callback('/\{\{\s*([a-z_]+)\s*\}\}/u', function (array $m) use ($values) {
            $value = e($values[$m[1]] ?? '');

            return $m[1] === 'actividad' ? "<strong>{$value}</strong>" : $value;
        }, $html) ?? $html;
    }

    private function color(?string $color, string $fallback): string
    {
        return is_string($color) && preg_match('/^#[0-9A-Fa-f]{6}$/', $color) ? Str::upper($color) : $fallback;
    }

    private function image(?string $path): ?string
    {
        if (! $path || ! Storage::disk('local')->exists($path)) {
            return null;
        }

        $mime = Storage::disk('local')->mimeType($path) ?: 'image/png';

        return 'data:'.$mime.';base64,'.base64_encode(Storage::disk('local')->get($path));
    }

    private function qr(string $url): string
    {
        $png = (new QRCode(new QROptions([
            'outputType' => QROutputInterface::CUSTOM,
            'outputInterface' => QRGdImagePNG::class,
            'outputBase64' => false,
            'eccLevel' => EccLevel::M,
            'scale' => 6,
            'addQuietzone' => false,
        ])))->render($url);

        return 'data:image/png;base64,'.base64_encode($png);
    }
}
