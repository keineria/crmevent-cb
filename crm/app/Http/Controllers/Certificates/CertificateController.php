<?php

namespace App\Http\Controllers\Certificates;

use App\Http\Controllers\Controller;
use App\Jobs\SendCertificate;
use App\Mail\CertificateMail;
use App\Models\AuditLog;
use App\Models\Certificate;
use App\Models\CertificateCategory;
use App\Models\CertificateRecipient;
use App\Models\EventActivity;
use App\Models\MailSetting;
use App\Services\Certificates\CertificateAudience;
use App\Services\Certificates\CertificatePdf;
use App\Services\Mail\CrmMailer;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response as HttpResponse;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;
use Throwable;

/**
 * Certificados: categories, the activities each one is given for, and sending them.
 */
class CertificateController extends Controller
{
    public function __construct(private CertificateAudience $audience) {}

    public function index(): Response
    {
        $categories = CertificateCategory::with(['certificates' => fn ($q) => $q->orderBy('activity_date')->orderBy('activity_name')])
            ->orderBy('activity_type')->orderBy('name')->get();
        $certificateIds = $categories->flatMap->certificates->pluck('id');
        $sent = CertificateRecipient::whereIn('certificate_id', $certificateIds)->whereNotNull('sent_at')
            ->selectRaw('certificate_id, count(*) as total')->groupBy('certificate_id')->pluck('total', 'certificate_id');
        $taken = $categories->flatMap->certificates->groupBy('certificate_category_id')->map->pluck('evr_activity_id');

        return Inertia::render('certificates/index', [
            'categories' => $categories->map(fn (CertificateCategory $c) => [
                'id' => $c->id,
                'name' => $c->name,
                'activity_type' => $c->activity_type,
                'template' => $c->template,
                'primary_color' => $c->primary_color,
                'accent_color' => $c->accent_color,
                'has_logo' => $c->logo_path !== null,
                'has_background' => $c->background_path !== null,
                'certificates' => $c->certificates->map(fn (Certificate $cert) => [
                    'id' => $cert->id,
                    'activity_name' => $cert->activity_name,
                    'activity_date' => $cert->activity_date?->toDateString(),
                    'hours' => $cert->hours !== null ? (float) $cert->hours : null,
                    'require_check_in' => $cert->require_check_in,
                    'sent' => (int) ($sent[$cert->id] ?? 0),
                    'sent_at' => $cert->sent_at?->toIso8601String(),
                ]),
            ]),
            'activities' => EventActivity::whereIn('type', array_keys(CertificateCategory::ACTIVITY_TYPES))
                ->where('status', '!=', 'archived')
                ->orderBy('event_date')->orderBy('start_time')->orderBy('name')
                ->get(['evr_id', 'type', 'name', 'event_date', 'start_time', 'price'])
                ->map(fn (EventActivity $a) => [
                    'id' => $a->evr_id,
                    'type' => $a->type,
                    'name' => $a->name,
                    'date' => $a->event_date?->toDateString(),
                    'start_time' => $a->start_time ? substr($a->start_time, 0, 5) : null,
                    'price' => (float) $a->price,
                ]),
            'taken' => $taken,
            'types' => CertificateCategory::ACTIVITY_TYPES,
            'templates' => collect(CertificateCategory::TEMPLATES)->map->label,
        ]);
    }

    /**
     * Ties a category to one or more activities of its type: one certificate per activity.
     */
    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'category' => ['required', 'integer', 'exists:certificate_categories,id'],
            'activities' => ['required', 'array', 'min:1', 'max:100'],
            'activities.*' => ['integer'],
            'hours' => ['nullable', 'numeric', 'min:0.5', 'max:999'],
            'require_check_in' => ['required', 'boolean'],
        ], ['activities.required' => 'Elige al menos una actividad.'], ['hours' => 'horas']);

        $category = CertificateCategory::findOrFail($data['category']);
        $activities = EventActivity::whereIn('evr_id', $data['activities'])->where('type', $category->activity_type)->get();
        $created = 0;

        foreach ($activities as $activity) {
            $certificate = Certificate::firstOrCreate(
                ['certificate_category_id' => $category->id, 'evr_activity_id' => $activity->evr_id],
                [
                    'activity_name' => $activity->name,
                    'activity_date' => $activity->event_date,
                    'hours' => $data['hours'] ?? null,
                    'require_check_in' => $data['require_check_in'],
                    'created_by' => $request->user()->id,
                ],
            );
            if ($certificate->wasRecentlyCreated) {
                AuditLog::record('certificate.created', $certificate, ['actividad' => $activity->name]);
                $created++;
            }
        }

        if (! $created) {
            return back()->with('error', 'Esas actividades ya tenían certificado en esta categoría.');
        }

        return back()->with('success', $created === 1 ? 'Certificado creado.' : "{$created} certificados creados.");
    }

    public function show(Certificate $certificate): Response
    {
        $certificate->load('category', 'sender:id,name');
        // Gives everyone who qualifies their code now, so each certificate can be downloaded and verified before sending.
        $this->audience->sync($certificate);
        $recipients = $certificate->recipients()->get()->keyBy('person_key');
        $people = $this->audience->people($certificate);

        // People who got it and later lost their seat or check-in still show, so nothing sent disappears.
        $rows = $people->map(function (array $p) use ($recipients) {
            $r = $recipients->get($p['person_key']);

            return [
                'key' => $p['person_key'],
                'name' => $r?->sent_at ? $r->full_name : $p['participant']->full_name,
                'document' => $p['participant']->document_number,
                'email' => $r?->sent_at ? $r->email : $p['email'],
                'checked_in' => $p['checked_in'],
                'checked_in_at' => $p['checked_in_at'],
                'eligible' => $p['eligible'],
                ...$this->recipientData($r),
            ];
        });
        $orphans = $recipients->except($people->pluck('person_key')->all())->filter(fn (CertificateRecipient $r) => $r->sent_at)
            ->map(fn (CertificateRecipient $r) => [
                'key' => $r->person_key, 'name' => $r->full_name, 'document' => $r->document_number, 'email' => $r->email,
                'checked_in' => false, 'checked_in_at' => null, 'eligible' => false, ...$this->recipientData($r),
            ]);
        $rows = $rows->concat($orphans->values())->values();

        $pending = $rows->filter(fn (array $r) => $r['eligible'] && $r['email'] && ! $r['sent_at'] && ! $r['queued']);

        return Inertia::render('certificates/show', [
            'certificate' => [
                'id' => $certificate->id,
                'activity_name' => $certificate->activity_name,
                'activity_date' => $certificate->activity_date?->toDateString(),
                'hours' => $certificate->hours !== null ? (float) $certificate->hours : null,
                'require_check_in' => $certificate->require_check_in,
                'sent_at' => $certificate->sent_at?->toIso8601String(),
                'sent_by' => $certificate->sender?->name,
                'category' => ['id' => $certificate->category->id, 'name' => $certificate->category->name, 'type' => $certificate->category->activity_type],
            ],
            'people' => $rows,
            'stats' => [
                'seats' => $people->count(),
                'checked_in' => $people->where('checked_in', true)->count(),
                'eligible' => $people->where('eligible', true)->count(),
                'without_email' => $people->where('eligible', true)->whereNull('email')->count(),
                'pending' => $pending->count(),
                'queued' => $rows->where('queued', true)->count(),
                'sent' => $rows->whereNotNull('sent_at')->count(),
                'failed' => $rows->whereNotNull('failed_at')->whereNull('sent_at')->count(),
                'per_hour' => (int) config('crm.certificate_emails_per_hour'),
            ],
            'mailReady' => (bool) MailSetting::where('profile', MailSetting::PROFILE_TRANSACTIONAL)->first()?->isActive(),
            'isPast' => $certificate->activity_date === null || $certificate->activity_date->lte(now('America/Bogota')->startOfDay()),
        ]);
    }

    public function update(Request $request, Certificate $certificate): RedirectResponse
    {
        $data = $request->validate([
            'hours' => ['nullable', 'numeric', 'min:0.5', 'max:999'],
            'require_check_in' => ['required', 'boolean'],
        ], [], ['hours' => 'horas']);

        $certificate->update($data);
        AuditLog::record('certificate.updated', $certificate, $certificate->getChanges() ? ['campos' => array_keys(array_diff_key($certificate->getChanges(), ['updated_at' => 1]))] : []);

        return back()->with('success', 'Certificado actualizado.');
    }

    public function destroy(Certificate $certificate): RedirectResponse
    {
        if ($certificate->recipients()->whereNotNull('sent_at')->exists()) {
            return back()->with('error', 'Este certificado ya se envió. Se conserva para que siga siendo verificable.');
        }

        AuditLog::record('certificate.deleted', $certificate);
        $certificate->delete();

        return to_route('certificates.index')->with('success', "Se quitó el certificado de {$certificate->activity_name}.");
    }

    /**
     * The certificate of this activity with the sample person.
     */
    public function preview(Certificate $certificate, CertificatePdf $pdf): HttpResponse
    {
        return $this->pdfResponse($pdf->sample($certificate->category, $certificate), 'vista-previa.pdf', inline: true);
    }

    public function test(Request $request, Certificate $certificate, CrmMailer $mailer): RedirectResponse
    {
        $data = $request->validate(['email' => ['required', 'email', 'max:255']]);

        if (! $this->mailReady()) {
            return back()->with('error', 'Primero configura y verifica el correo transaccional en Configuración › Correo de envíos.');
        }

        try {
            $mailer->for(MailSetting::PROFILE_TRANSACTIONAL)->to($data['email'])->send(CertificateMail::sample($certificate));
        } catch (Throwable $e) {
            report($e);

            return back()->with('error', 'El servidor de correo rechazó la prueba: '.$e->getMessage());
        }

        AuditLog::record('certificate.tested', $certificate, ['correo' => $data['email']]);

        return back()->with('success', "Prueba enviada a {$data['email']} con una persona de ejemplo. Revisa también la carpeta de spam.");
    }

    /**
     * Sends the certificate to everyone who qualifies and has not got it yet, paced under the mailbox's hourly limit.
     */
    public function send(Request $request, Certificate $certificate): RedirectResponse
    {
        if (! $this->mailReady()) {
            return back()->with('error', 'Primero configura y verifica el correo transaccional en Configuración › Correo de envíos.');
        }

        $perHour = max(1, (int) config('crm.certificate_emails_per_hour'));
        $count = 0;

        foreach ($this->audience->sync($certificate) as $recipient) {
            $queued = $recipient->queued_at && $recipient->queued_at->gt(now()->subDay());
            if ($recipient->sent_at || $queued || ! $recipient->email) {
                continue;
            }
            SendCertificate::dispatch($recipient)->delay(now()->addSeconds(intdiv($count * 3600, $perHour)));
            $recipient->update(['queued_at' => now(), 'failed_at' => null, 'error' => null]);
            $count++;
        }

        if (! $count) {
            return back()->with('error', 'No hay certificados pendientes por enviar en esta actividad.');
        }

        $certificate->update(['sent_at' => now(), 'sent_by' => $request->user()->id]);
        AuditLog::record('certificate.sent', $certificate, ['correos' => $count]);
        $hours = (int) ceil($count / $perHour);

        return back()->with('success', "Enviando {$count} certificados, {$perHour} por hora".($hours > 1 ? " (unas {$hours} horas en total)." : '.'));
    }

    public function resend(CertificateRecipient $recipient): RedirectResponse
    {
        if (! $this->mailReady()) {
            return back()->with('error', 'Primero configura y verifica el correo transaccional en Configuración › Correo de envíos.');
        }
        if (! $recipient->email) {
            return back()->with('error', "{$recipient->full_name} no tiene correo, ni la persona que compró.");
        }

        SendCertificate::dispatch($recipient, resend: true);
        $recipient->update(['queued_at' => now(), 'failed_at' => null, 'error' => null]);
        AuditLog::record('certificate.resent', $recipient, ['correo' => $recipient->email]);

        return back()->with('success', "Enviando el certificado a {$recipient->email}.");
    }

    /**
     * One person's certificate, to print or send by hand.
     */
    public function download(Request $request, CertificateRecipient $recipient, CertificatePdf $pdf): HttpResponse
    {
        AuditLog::record('certificate.downloaded', $recipient);

        return $this->pdfResponse($pdf->forRecipient($recipient), 'Certificado-'.Str::slug($recipient->full_name).'.pdf', inline: $request->boolean('ver'));
    }

    /**
     * @return array{recipient: int|null, code: string|null, queued: bool, sent_at: string|null, failed_at: string|null, error: string|null}
     */
    private function recipientData(?CertificateRecipient $r): array
    {
        return [
            'recipient' => $r?->id,
            'code' => $r?->code,
            'queued' => (bool) ($r && ! $r->sent_at && ! $r->failed_at && $r->queued_at?->gt(now()->subDay())),
            'sent_at' => $r?->sent_at?->toIso8601String(),
            'failed_at' => $r?->failed_at?->toIso8601String(),
            'error' => $r?->error,
        ];
    }

    private function mailReady(): bool
    {
        return (bool) MailSetting::where('profile', MailSetting::PROFILE_TRANSACTIONAL)->first()?->isActive();
    }

    private function pdfResponse(string $pdf, string $name, bool $inline): HttpResponse
    {
        return response($pdf, 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => ($inline ? 'inline' : 'attachment').'; filename="'.$name.'"',
            'Cache-Control' => 'no-store',
        ]);
    }
}
