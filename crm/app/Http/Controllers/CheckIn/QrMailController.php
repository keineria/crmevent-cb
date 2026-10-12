<?php

namespace App\Http\Controllers\CheckIn;

use App\Http\Controllers\Controller;
use App\Mail\CheckinQrMail;
use App\Models\AuditLog;
use App\Models\MailSetting;
use App\Models\MailTemplate;
use App\Services\Mail\CrmMailer;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Throwable;

/**
 * Check-in › Correo del QR: the wording of the email that carries each participant's QR.
 */
class QrMailController extends Controller
{
    public function edit(): Response
    {
        $template = MailTemplate::system(MailTemplate::CHECKIN_QR);

        return Inertia::render('checkin/qr-mail', [
            'template' => [
                'name' => $template->name,
                'subject' => $template->subject,
                'preheader' => $template->preheader,
                'body' => $template->body,
                'updated_at' => $template->updated_at?->toIso8601String(),
            ],
            'fields' => CheckinQrMail::FIELDS,
        ]);
    }

    public function update(Request $request): RedirectResponse
    {
        $data = $request->validate(self::rules());
        $template = MailTemplate::system(MailTemplate::CHECKIN_QR);

        $template->update([
            'subject' => $data['subject'],
            'preheader' => $data['preheader'] ?? null,
            'body' => $data['body'],
            'updated_by' => $request->user()->id,
        ]);

        AuditLog::record('checkin.qr_mail.updated', $template);

        return back()->with('success', 'Correo del QR guardado. Los próximos envíos usan este texto.');
    }

    public function preview(Request $request): JsonResponse
    {
        $data = $request->validate([
            'subject' => ['nullable', 'string', 'max:200'],
            'preheader' => ['nullable', 'string', 'max:200'],
            'body' => ['nullable', 'string', 'max:100000'],
        ]);

        $mail = CheckinQrMail::sample($data);

        return response()->json(['subject' => $mail->renderedSubject(), 'html' => $mail->render()]);
    }

    public function test(Request $request, CrmMailer $mailer): RedirectResponse
    {
        $data = $request->validate(['email' => ['required', 'email', 'max:255']]);

        if (! MailSetting::where('profile', MailSetting::PROFILE_TRANSACTIONAL)->first()?->isActive()) {
            return back()->with('error', 'Primero configura y verifica el correo transaccional en Configuración › Correo de envíos.');
        }

        try {
            $mailer->for(MailSetting::PROFILE_TRANSACTIONAL)->to($data['email'])->send(CheckinQrMail::sample());
        } catch (Throwable $e) {
            report($e);

            return back()->with('error', 'El servidor de correo rechazó la prueba: '.$e->getMessage());
        }

        return back()->with('success', "Prueba enviada a {$data['email']} con datos de ejemplo. Revisa también la carpeta de spam.");
    }

    /**
     * @return array<string, list<string>>
     */
    private static function rules(): array
    {
        return [
            'subject' => ['required', 'string', 'max:200'],
            'preheader' => ['nullable', 'string', 'max:200'],
            'body' => ['required', 'string', 'max:100000'],
        ];
    }
}
