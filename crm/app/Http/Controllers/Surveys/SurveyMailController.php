<?php

namespace App\Http\Controllers\Surveys;

use App\Http\Controllers\Controller;
use App\Mail\SurveyMail;
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
 * Encuestas › Correo: the wording of the email that invites each person to the survey.
 */
class SurveyMailController extends Controller
{
    public function edit(): Response
    {
        $template = MailTemplate::system(MailTemplate::SURVEY);

        return Inertia::render('surveys/mail', [
            'template' => [
                'name' => $template->name,
                'subject' => $template->subject,
                'preheader' => $template->preheader,
                'body' => $template->body,
                'updated_at' => $template->updated_at?->toIso8601String(),
            ],
            'fields' => SurveyMail::FIELDS,
        ]);
    }

    public function update(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'subject' => ['required', 'string', 'max:200'],
            'preheader' => ['nullable', 'string', 'max:200'],
            'body' => ['required', 'string', 'max:100000', 'regex:/\{\{\s*(encuesta|enlace)\s*\}\}/u'],
        ], ['body.regex' => 'Incluye {{encuesta}} (el botón) o {{enlace}}: sin eso nadie puede abrir su encuesta.']);
        $template = MailTemplate::system(MailTemplate::SURVEY);

        $template->update([...$data, 'preheader' => $data['preheader'] ?? null, 'updated_by' => $request->user()->id]);
        AuditLog::record('survey.mail_updated', $template);

        return back()->with('success', 'Correo de la encuesta guardado. Los próximos envíos usan este texto.');
    }

    public function preview(Request $request): JsonResponse
    {
        $data = $request->validate([
            'subject' => ['nullable', 'string', 'max:200'],
            'preheader' => ['nullable', 'string', 'max:200'],
            'body' => ['nullable', 'string', 'max:100000'],
        ]);

        $mail = SurveyMail::sample($data);

        return response()->json(['subject' => $mail->renderedSubject(), 'html' => $mail->render()]);
    }

    public function test(Request $request, CrmMailer $mailer): RedirectResponse
    {
        $data = $request->validate(['email' => ['required', 'email', 'max:255']]);

        if (! MailSetting::where('profile', MailSetting::PROFILE_TRANSACTIONAL)->first()?->isActive()) {
            return back()->with('error', 'Primero configura y verifica el correo transaccional en Configuración › Correo de envíos.');
        }

        try {
            $mailer->for(MailSetting::PROFILE_TRANSACTIONAL)->to($data['email'])->send(SurveyMail::sample());
        } catch (Throwable $e) {
            report($e);

            return back()->with('error', 'El servidor de correo rechazó la prueba: '.$e->getMessage());
        }

        AuditLog::record('survey.tested', null, ['correo' => $data['email']]);

        return back()->with('success', "Prueba enviada a {$data['email']} con datos de ejemplo. Revisa también la carpeta de spam.");
    }
}
