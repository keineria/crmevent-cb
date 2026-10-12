<?php

namespace App\Http\Controllers\Certificates;

use App\Http\Controllers\Controller;
use App\Models\CertificateRecipient;
use App\Services\Certificates\CertificatePdf;
use Illuminate\Http\Response as HttpResponse;
use Illuminate\Support\Str;
use Illuminate\View\View;

/**
 * Public page the certificate's QR opens: says whether the code belongs to a certificate issued by the fair.
 * Shows only the name, the activity and the date, never the document or the email.
 */
class CertificateVerificationController extends Controller
{
    public function show(string $code): View
    {
        $recipient = $this->find($code);

        return view('public.certificate', [
            'title' => $recipient ? 'Certificado válido' : 'Certificado no encontrado',
            'recipient' => $recipient,
            'date' => $recipient?->certificate->activity_date ? app(CertificatePdf::class)->date($recipient->certificate->activity_date) : null,
            'code' => Str::limit(CertificateRecipient::normalizeCode($code), 20, ''),
        ]);
    }

    public function pdf(string $code, CertificatePdf $pdf): HttpResponse
    {
        $recipient = $this->find($code);
        abort_unless($recipient !== null, 404);

        return response($pdf->forRecipient($recipient), 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'attachment; filename="Certificado-'.Str::slug($recipient->full_name).'.pdf"',
            'Cache-Control' => 'no-store',
            'X-Robots-Tag' => 'noindex',
        ]);
    }

    /**
     * A code exists only for someone who qualifies (or already got it), and it can't be guessed.
     */
    private function find(string $code): ?CertificateRecipient
    {
        $code = CertificateRecipient::normalizeCode($code);
        if (! preg_match('/^CERT-[A-Z0-9]{10}$/', $code)) {
            return null;
        }

        return CertificateRecipient::with('certificate.category')->where('code', $code)->first();
    }
}
