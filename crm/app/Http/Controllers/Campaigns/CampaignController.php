<?php

namespace App\Http\Controllers\Campaigns;

use App\Http\Controllers\Controller;
use App\Mail\CampaignMail;
use App\Models\Campaign;
use App\Models\CampaignClick;
use App\Models\CampaignRecipient;
use App\Models\Contact;
use App\Models\MailSetting;
use App\Models\MailTemplate;
use App\Services\Campaigns\CampaignDispatcher;
use App\Services\Campaigns\CampaignRenderer;
use App\Services\Campaigns\Segment;
use App\Services\Mail\CrmMailer;
use App\Support\Roles;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;
use Throwable;

class CampaignController extends Controller
{
    /** Dates in forms are typed in the fair's time zone. */
    public const TIMEZONE = 'America/Bogota';

    public function __construct(private CampaignDispatcher $dispatcher) {}

    public function index(Request $request): Response
    {
        $campaigns = Campaign::query()
            ->withStats()
            ->with('creator:id,name')
            ->orderByRaw('case when status in (?, ?, ?) then 0 else 1 end', [Campaign::SENDING, Campaign::PAUSED, Campaign::SCHEDULED])
            ->orderByDesc('updated_at')
            ->paginate(20)
            ->through(fn (Campaign $c) => [
                'id' => $c->id,
                'name' => $c->name,
                'subject' => $c->subject,
                'status' => $c->status,
                'segment' => Segment::describe($c->segment),
                'scheduled_at' => $c->scheduled_at?->toIso8601String(),
                'started_at' => $c->started_at?->toIso8601String(),
                'updated_at' => $c->updated_at->toIso8601String(),
                'creator' => $c->creator?->name,
                ...$this->stats($c),
            ]);

        return Inertia::render('campaigns/index', [
            'campaigns' => $campaigns,
            'templates' => MailTemplate::orderBy('name')->get(['id', 'name']),
            'canEdit' => $request->user()->hasAnyRole(Roles::CAMPAIGN_EDITORS),
            'senderReady' => $this->senderReady(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate(['template_id' => ['nullable', 'integer', 'exists:mail_templates,id']]);
        $template = isset($data['template_id']) ? MailTemplate::find($data['template_id']) : null;

        $campaign = Campaign::create([
            'name' => $template ? "{$template->name} · ".now(self::TIMEZONE)->translatedFormat('j M Y') : 'Nueva campaña',
            'subject' => $template->subject ?? '',
            'preheader' => $template?->preheader,
            'body' => $template->body ?? "# Hola {{nombre|amigo}}\n\nEscribe aquí tu mensaje.\n\n[[Ver más|https://colombiabirdfair.com]]",
            'segment' => [],
            'status' => Campaign::DRAFT,
            'created_by' => $request->user()->id,
            'updated_by' => $request->user()->id,
        ]);

        return to_route('campaigns.edit', $campaign);
    }

    public function edit(Request $request, Campaign $campaign): Response|RedirectResponse
    {
        if (! $campaign->isEditable()) {
            return to_route('campaigns.show', $campaign);
        }

        $broadcast = MailSetting::where('profile', MailSetting::PROFILE_BROADCAST)->first();
        [$hourly, $daily] = $this->dispatcher->limits();

        return Inertia::render('campaigns/edit', [
            'campaign' => [
                'id' => $campaign->id,
                'name' => $campaign->name,
                'subject' => $campaign->subject,
                'preheader' => $campaign->preheader,
                'body' => $campaign->body,
                'segment' => (object) Segment::clean($campaign->segment),
                'status' => $campaign->status,
                'scheduled_at' => $campaign->scheduled_at?->setTimezone(self::TIMEZONE)->format('Y-m-d\TH:i'),
            ],
            'options' => Segment::options(),
            'fields' => CampaignRenderer::FIELDS,
            'sender' => [
                'ready' => $this->senderReady(),
                'from' => $broadcast?->from_address ? trim("{$broadcast->from_name} <{$broadcast->from_address}>") : null,
                'hourly' => $hourly,
                'daily' => $daily,
            ],
            'testEmail' => $request->user()->email,
        ]);
    }

    public function update(Request $request, Campaign $campaign): RedirectResponse
    {
        abort_unless($campaign->isEditable(), 409, 'La campaña ya empezó a enviarse.');

        $data = $request->validate([...self::contentRules(), ...Segment::rules()]);

        $campaign->update([
            'name' => $data['name'],
            'subject' => $data['subject'] ?? '',
            'preheader' => $data['preheader'] ?? null,
            'body' => $data['body'] ?? '',
            'segment' => Segment::clean($data['segment'] ?? []),
            'updated_by' => $request->user()->id,
        ]);

        return back()->with('success', 'Campaña guardada.');
    }

    public function destroy(Campaign $campaign): RedirectResponse
    {
        abort_unless($campaign->status === Campaign::DRAFT, 409, 'Solo se pueden borrar borradores.');

        $campaign->delete();

        return to_route('campaigns.index')->with('success', 'Borrador eliminado.');
    }

    public function preview(Request $request): JsonResponse
    {
        $data = $request->validate([
            'subject' => ['nullable', 'string', 'max:200'],
            'preheader' => ['nullable', 'string', 'max:200'],
            'body' => ['nullable', 'string', 'max:100000'],
        ]);

        $mail = new CampaignMail($this->sampleContact(), $data['subject'] ?? '', $data['preheader'] ?? null, $data['body'] ?? '');

        return response()->json([
            'subject' => app(CampaignRenderer::class)->subject($data['subject'] ?? '', $mail->contact),
            'html' => $mail->renderHtml(),
        ]);
    }

    public function count(Request $request): JsonResponse
    {
        $data = $request->validate(Segment::rules());

        return response()->json([
            'count' => Segment::query($data['segment'] ?? [])->count(),
            'summary' => Segment::describe($data['segment'] ?? []),
        ]);
    }

    public function test(Request $request, Campaign $campaign, CrmMailer $mailer): RedirectResponse
    {
        $data = $request->validate(['email' => ['required', 'email', 'max:255']]);

        if (! $this->senderReady()) {
            return back()->with('error', 'Primero configura y verifica el correo de difusión en Configuración › Correo de envíos.');
        }

        $mail = new CampaignMail($this->sampleContact(), "[Prueba] {$campaign->subject}", $campaign->preheader, $campaign->body);

        try {
            $mailer->for(MailSetting::PROFILE_BROADCAST)->to($data['email'])->send($mail);
        } catch (Throwable $e) {
            report($e);

            return back()->with('error', 'El servidor de correo rechazó la prueba: '.$e->getMessage());
        }

        return back()->with('success', "Prueba enviada a {$data['email']}. Revisa también la carpeta de spam.");
    }

    public function launch(Request $request, Campaign $campaign): RedirectResponse
    {
        abort_unless($campaign->isEditable(), 409, 'La campaña ya empezó a enviarse.');

        $data = $request->validate(['scheduled_at' => ['nullable', 'date_format:Y-m-d\TH:i']]);

        $missing = collect(['subject' => 'el asunto', 'body' => 'el contenido'])->filter(fn ($label, $field) => trim((string) $campaign->{$field}) === '');
        if ($missing->isNotEmpty()) {
            return back()->with('error', 'Falta '.$missing->implode(' y ').'.');
        }
        if (! $this->senderReady()) {
            return back()->with('error', 'Primero configura y verifica el correo de difusión en Configuración › Correo de envíos.');
        }
        if (! Segment::query($campaign->segment)->exists()) {
            return back()->with('error', 'Nadie del público elegido acepta difusiones. Cambia los filtros.');
        }

        $scheduledAt = isset($data['scheduled_at']) ? Carbon::createFromFormat('Y-m-d\TH:i', $data['scheduled_at'], self::TIMEZONE)->utc() : null;
        if ($scheduledAt && $scheduledAt->isPast()) {
            $scheduledAt = null;
        }

        $campaign->update(['scheduled_at' => $scheduledAt, 'updated_by' => $request->user()->id]);
        $this->dispatcher->launch($campaign);

        return to_route('campaigns.show', $campaign)->with('success', $scheduledAt
            ? 'Campaña programada para el '.$scheduledAt->setTimezone(self::TIMEZONE)->translatedFormat('j \d\e F \a \l\a\s g:i a').'.'
            : 'La campaña empezó a enviarse.');
    }

    public function pause(Campaign $campaign): RedirectResponse
    {
        abort_unless($campaign->status === Campaign::SENDING, 409);

        $campaign->update(['status' => Campaign::PAUSED]);

        return back()->with('success', 'Envío en pausa. Los correos que ya estaban en cola pueden salir en el próximo minuto.');
    }

    public function resume(Campaign $campaign): RedirectResponse
    {
        abort_unless($campaign->status === Campaign::PAUSED, 409);

        $campaign->update(['status' => Campaign::SENDING]);

        return back()->with('success', 'Envío reanudado.');
    }

    public function cancel(Campaign $campaign): RedirectResponse
    {
        if ($campaign->status === Campaign::SCHEDULED) {
            $campaign->update(['status' => Campaign::DRAFT, 'scheduled_at' => null]);

            return to_route('campaigns.edit', $campaign)->with('success', 'Se quitó la programación. La campaña volvió a borrador.');
        }

        abort_unless(in_array($campaign->status, [Campaign::SENDING, Campaign::PAUSED], true), 409);

        DB::transaction(function () use ($campaign) {
            $campaign->update(['status' => Campaign::CANCELLED, 'finished_at' => now()]);
            $campaign->recipients()->where('status', CampaignRecipient::PENDING)->update(['status' => CampaignRecipient::SKIPPED, 'error' => 'Campaña cancelada.']);
        });

        return back()->with('success', 'Campaña cancelada. No saldrán más correos.');
    }

    public function retry(Campaign $campaign): RedirectResponse
    {
        abort_unless(in_array($campaign->status, [Campaign::SENDING, Campaign::PAUSED, Campaign::SENT], true), 409);

        $count = $campaign->recipients()->where('status', CampaignRecipient::FAILED)->update(['status' => CampaignRecipient::PENDING, 'error' => null]);

        if ($count && $campaign->status === Campaign::SENT) {
            $campaign->update(['status' => Campaign::SENDING, 'finished_at' => null]);
        }

        return back()->with('success', $count ? "Se volverán a intentar {$count} correos." : 'No hay correos fallidos.');
    }

    public function duplicate(Request $request, Campaign $campaign): RedirectResponse
    {
        $copy = Campaign::create([
            ...$campaign->only(['subject', 'preheader', 'body', 'segment']),
            'name' => "{$campaign->name} (copia)",
            'status' => Campaign::DRAFT,
            'created_by' => $request->user()->id,
            'updated_by' => $request->user()->id,
        ]);

        return to_route('campaigns.edit', $copy)->with('success', 'Copia creada como borrador.');
    }

    public function show(Request $request, Campaign $campaign): Response|RedirectResponse
    {
        $canEdit = $request->user()->hasAnyRole(Roles::CAMPAIGN_EDITORS);

        if ($campaign->status === Campaign::DRAFT && $canEdit) {
            return to_route('campaigns.edit', $campaign);
        }

        $filters = $request->validate([
            'q' => ['nullable', 'string', 'max:120'],
            'estado' => ['nullable', 'in:sent,failed,skipped,pending,opened,clicked,unsubscribed'],
        ]);

        $campaign->loadMissing('creator:id,name');
        $stats = $this->stats(Campaign::withStats()->findOrFail($campaign->id));
        $stats['unsubscribed'] = $this->unsubscribed($campaign)->count();

        $recipients = $campaign->recipients()
            ->with('contact:id,first_name,last_name')
            ->when($filters['q'] ?? null, fn (Builder $q, string $term) => $q->where('email', 'like', "%{$term}%"))
            ->when($filters['estado'] ?? null, fn (Builder $q, string $status) => match ($status) {
                'opened' => $q->whereNotNull('opened_at'),
                'clicked' => $q->whereNotNull('clicked_at'),
                'pending' => $q->whereIn('status', [CampaignRecipient::PENDING, CampaignRecipient::QUEUED]),
                'unsubscribed' => $q->whereIn('id', $this->unsubscribed($campaign)->select('campaign_recipients.id')),
                default => $q->where('status', $status),
            })
            ->orderByRaw('case when status = ? then 0 else 1 end', [CampaignRecipient::FAILED])
            ->orderBy('id')
            ->paginate(50)
            ->withQueryString()
            ->through(fn (CampaignRecipient $r) => [
                'id' => $r->id,
                'contact_id' => $r->contact_id,
                'name' => $r->contact?->fullName() ?: null,
                'email' => $r->email,
                'status' => $r->status,
                'error' => $r->error,
                'sent_at' => $r->sent_at?->toIso8601String(),
                'opened_at' => $r->opened_at?->toIso8601String(),
                'clicked_at' => $r->clicked_at?->toIso8601String(),
                'click_count' => $r->click_count,
            ]);

        $links = CampaignClick::query()
            ->join('campaign_recipients as r', 'r.id', '=', 'campaign_clicks.campaign_recipient_id')
            ->where('r.campaign_id', $campaign->id)
            ->groupBy('campaign_clicks.url')
            ->selectRaw('campaign_clicks.url, count(*) as clicks, count(distinct r.id) as people')
            ->orderByDesc('people')
            ->limit(10)
            ->get();

        [$hourly, $daily] = $this->dispatcher->limits();

        return Inertia::render('campaigns/show', [
            'campaign' => [
                'id' => $campaign->id,
                'name' => $campaign->name,
                'subject' => $campaign->subject,
                'status' => $campaign->status,
                'segment' => Segment::describe($campaign->segment),
                'scheduled_at' => $campaign->scheduled_at?->toIso8601String(),
                'started_at' => $campaign->started_at?->toIso8601String(),
                'finished_at' => $campaign->finished_at?->toIso8601String(),
                'creator' => $campaign->creator?->name,
            ],
            'stats' => $stats,
            'links' => $links->map(fn ($l) => ['url' => $l->url, 'clicks' => (int) $l->clicks, 'people' => (int) $l->people]),
            'recipients' => $recipients,
            'filters' => (object) $filters,
            'pace' => ['hourly' => $hourly, 'daily' => $daily],
            'tracking' => [
                'opens' => (bool) MailSetting::where('profile', MailSetting::PROFILE_BROADCAST)->value('track_opens'),
                'clicks' => (bool) MailSetting::where('profile', MailSetting::PROFILE_BROADCAST)->value('track_clicks'),
            ],
            'canEdit' => $canEdit,
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    public static function contentRules(): array
    {
        return [
            'name' => ['required', 'string', 'max:120'],
            'subject' => ['nullable', 'string', 'max:200'],
            'preheader' => ['nullable', 'string', 'max:200'],
            'body' => ['nullable', 'string', 'max:100000'],
        ];
    }

    /**
     * @return array<string, int>
     */
    private function stats(Campaign $c): array
    {
        return [
            'recipients' => (int) $c->recipients_count,
            'sent' => (int) $c->sent_count,
            'failed' => (int) $c->failed_count,
            'skipped' => (int) $c->skipped_count,
            'pending' => (int) $c->pending_count,
            'opened' => (int) $c->opened_count,
            'clicked' => (int) $c->clicked_count,
        ];
    }

    /**
     * Recipients who unsubscribed after this campaign reached them.
     *
     * @return Builder<CampaignRecipient>
     */
    private function unsubscribed(Campaign $campaign): Builder
    {
        return CampaignRecipient::query()
            ->join('contacts', 'contacts.id', '=', 'campaign_recipients.contact_id')
            ->where('campaign_recipients.campaign_id', $campaign->id)
            ->whereNotNull('campaign_recipients.sent_at')
            ->whereColumn('contacts.unsubscribed_at', '>=', 'campaign_recipients.sent_at');
    }

    private function senderReady(): bool
    {
        return (bool) MailSetting::where('profile', MailSetting::PROFILE_BROADCAST)->first()?->isActive();
    }

    private function sampleContact(): Contact
    {
        // Always the same made-up person, so the preview doesn't jump around and tests never show real data.
        return CampaignRenderer::sampleContact();
    }
}
