<?php

namespace App\Http\Controllers\CheckIn;

use App\Http\Controllers\Controller;
use App\Jobs\SendCheckinQr;
use App\Models\CheckIn;
use App\Models\EventActivity;
use App\Models\Registration;
use App\Models\RegistrationActivity;
use App\Models\RegistrationParticipant;
use App\Models\User;
use App\Services\CheckIn\CheckInDesk;
use App\Services\CheckIn\CheckinQr;
use App\Services\CheckIn\CheckInRefused;
use App\Services\CheckIn\ParticipantRecord;
use App\Support\Roles;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response as HttpResponse;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class CheckInController extends Controller
{
    public const MODE_ENTRANCE = 'entrada';

    public const MODE_TRIPS = 'salidas';

    public function __construct(private CheckInDesk $desk) {}

    public function index(Request $request): Response
    {
        $modes = $this->modesFor($request->user());
        $filters = $request->validate([
            'modo' => ['nullable', Rule::in($modes)],
            'fecha' => ['nullable', 'date_format:Y-m-d'],
            'salida' => ['nullable', 'integer'],
            'q' => ['nullable', 'string', 'max:200'],
            'persona' => ['nullable', 'integer'],
        ]);
        $mode = $filters['modo'] ?? $modes[0];
        $types = $this->typesFor($mode);
        $date = $filters['fecha'] ?? CheckInDesk::today();

        $dayActivities = EventActivity::whereIn('type', $types)->where('status', '!=', 'archived')->whereDate('event_date', $date)
            ->orderBy('start_time')->orderBy('name')->get(['evr_id', 'name', 'type', 'destination', 'start_time']);
        $counts = $this->countsFor($dayActivities->pluck('evr_id')->all());

        $trip = $mode === self::MODE_TRIPS ? $dayActivities->firstWhere('evr_id', $filters['salida'] ?? null) : null;

        // A QR, a document with one owner, or a pick from the list opens the person's record straight away.
        $method = null;
        $matches = null;
        $person = isset($filters['persona']) ? RegistrationParticipant::find($filters['persona']) : null;
        if (! $person && filled($filters['q'] ?? null)) {
            $found = $this->desk->search($filters['q']);
            $method = $found['method'];
            if ($found['participants']->count() === 1) {
                $person = $found['participants']->first();
            } else {
                $matches = $found['participants']->map(fn (RegistrationParticipant $p) => [
                    'id' => $p->id,
                    'name' => $p->full_name,
                    'document' => trim("{$p->document_type} {$p->document_number}") ?: null,
                    'entity' => $p->entity,
                    'order' => $p->registration->number,
                    'paid' => $p->registration->isPaid(),
                ])->values();
            }
        }
        $user = $request->user();

        return Inertia::render('checkin/index', [
            'mode' => $mode,
            'modes' => $modes,
            'date' => $date,
            'today' => CheckInDesk::today(),
            'dates' => EventActivity::whereIn('type', $types)->where('status', '!=', 'archived')->whereNotNull('event_date')
                ->orderBy('event_date')->distinct()->pluck('event_date')->map(fn ($d) => $d->toDateString())->unique()->values(),
            'activities' => $dayActivities->map(fn (EventActivity $a) => [
                'id' => $a->evr_id,
                'name' => $a->name,
                'type' => $a->type,
                'destination' => $a->destination,
                'start_time' => $a->start_time ? substr($a->start_time, 0, 5) : null,
                'expected' => $counts[$a->evr_id]['expected'] ?? 0,
                'checked_in' => $counts[$a->evr_id]['checked_in'] ?? 0,
            ]),
            'trip' => $trip?->evr_id,
            'roster' => $trip ? $this->roster($trip->evr_id) : null,
            'q' => $filters['q'] ?? '',
            'method' => $method ?? (isset($filters['persona']) ? CheckIn::METHOD_SEARCH : null),
            'matches' => $matches,
            'record' => $person ? ParticipantRecord::build($person, $types, $date) : null,
            'can' => [
                'edit_details' => $user->hasAnyRole(Roles::PARTICIPANT_EDITORS),
                'edit_activities' => $user->hasAnyRole(Roles::ACTIVITY_EDITORS),
            ],
            'options' => [
                'documents' => ParticipantRecord::DOCUMENT_TYPES,
                'shirts' => ['S', 'M', 'L', 'XL', 'XXL'],
                'meals' => ['traditional' => 'Tradicional', 'vegetarian' => 'Vegetariana', 'vegan' => 'Vegana'],
            ],
            'types' => EventActivity::TYPE_LABELS,
            'canManage' => $user->hasAnyRole(Roles::CHECKIN_MANAGERS),
            'qr' => $user->hasAnyRole(Roles::CHECKIN_MANAGERS) ? $this->qrStats() : null,
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'modo' => ['required', Rule::in($this->modesFor($request->user()))],
            'participant' => ['required', 'integer', 'exists:registration_participants,id'],
            'activities' => ['required', 'array', 'min:1', 'max:30'],
            'activities.*' => ['integer'],
            'method' => ['required', Rule::in(CheckIn::METHODS)],
        ]);

        $participant = RegistrationParticipant::with('registration')->findOrFail($data['participant']);

        try {
            $result = $this->desk->checkIn($participant, array_map('intval', $data['activities']), $this->typesFor($data['modo']), $data['method'], $request->user());
        } catch (CheckInRefused $e) {
            return back()->with('error', "{$participant->full_name}: {$e->getMessage()}");
        }

        $message = match (true) {
            $result['created'] > 0 && $result['already'] > 0 => "{$participant->full_name}: ingreso registrado ({$result['already']} ya estaba registrada).",
            $result['created'] > 0 => "{$participant->full_name}: ingreso registrado.",
            default => "{$participant->full_name} ya había ingresado.",
        };

        return back()->with($result['created'] > 0 ? 'success' : 'error', $message);
    }

    public function destroy(Request $request, CheckIn $checkIn): RedirectResponse
    {
        $user = $request->user();
        $types = $user->hasAnyRole(Roles::ENTRANCE_DESK) ? CheckIn::ENTRANCE_TYPES : [];
        $types = [...$types, ...($user->hasAnyRole(Roles::TRIP_DESK) ? CheckIn::TRIP_TYPES : [])];

        abort_unless(in_array($checkIn->activity?->type, $types, true), 403);
        abort_unless($user->hasAnyRole(Roles::CHECKIN_MANAGERS) || $checkIn->user_id === $user->id, 403, 'Solo quien registró el ingreso o un administrador puede deshacerlo.');

        $name = $checkIn->participant->full_name;
        $this->desk->undo($checkIn);

        return back()->with('success', "Se deshizo el ingreso de {$name}.");
    }

    public function qr(RegistrationParticipant $participant, CheckinQr $qr): HttpResponse
    {
        return response($qr->png($participant), 200, [
            'Content-Type' => 'image/png',
            'Content-Disposition' => 'inline; filename="QR-'.$participant->checkin_code.'.png"',
            'Cache-Control' => 'private, max-age=3600',
        ]);
    }

    public function sendQr(RegistrationParticipant $participant): RedirectResponse
    {
        if (! $participant->registration->isPaid()) {
            return back()->with('error', 'El pedido no está pagado.');
        }
        if (! $participant->qrEmail()) {
            return back()->with('error', "{$participant->full_name} no tiene correo, ni la persona que compró.");
        }

        SendCheckinQr::dispatch($participant, resend: true);

        return back()->with('success', "Enviando el QR a {$participant->qrEmail()}.");
    }

    public function sendPendingQrs(): RedirectResponse
    {
        // Spread the emails out so the mailbox stays under its provider's hourly sending limit.
        $perHour = max(1, (int) config('crm.qr_emails_per_hour'));
        $count = 0;

        $seen = [];

        $this->pendingQrQuery()->with('registration:id,status,billing')->chunkById(200, function ($participants) use (&$count, &$seen, $perHour) {
            foreach ($participants as $participant) {
                // One email per person: any of their codes opens their whole record at the desk.
                $person = $participant->evr_participant_id > 0 ? "evr{$participant->evr_participant_id}" : "row{$participant->id}";
                if (isset($seen[$person]) || ! $participant->qrEmail()) {
                    continue;
                }
                $seen[$person] = true;
                SendCheckinQr::dispatch($participant)->delay(now()->addSeconds(intdiv($count * 3600, $perHour)));
                ParticipantRecord::rows($participant)->toQuery()->update(['qr_queued_at' => now()]);
                $count++;
            }
        });

        if (! $count) {
            return back()->with('error', 'No hay códigos QR pendientes por enviar.');
        }

        $hours = (int) ceil($count / $perHour);

        return back()->with('success', "Enviando {$count} códigos QR, {$perHour} por hora".($hours > 1 ? " (unas {$hours} horas en total)." : '.'));
    }

    /**
     * @return list<string>
     */
    private function modesFor(User $user): array
    {
        $modes = [];
        if ($user->hasAnyRole(Roles::ENTRANCE_DESK)) {
            $modes[] = self::MODE_ENTRANCE;
        }
        if ($user->hasAnyRole(Roles::TRIP_DESK)) {
            $modes[] = self::MODE_TRIPS;
        }

        return $modes;
    }

    /**
     * @return list<string>
     */
    private function typesFor(string $mode): array
    {
        return $mode === self::MODE_TRIPS ? CheckIn::TRIP_TYPES : CheckIn::ENTRANCE_TYPES;
    }

    /**
     * Seats taken in paid orders and arrivals so far, per plugin activity id.
     *
     * @param  list<int>  $evrIds
     * @return array<int, array{expected: int, checked_in: int}>
     */
    private function countsFor(array $evrIds): array
    {
        if (! $evrIds) {
            return [];
        }

        $expected = $this->activeAssignments()->whereIn('evr_activity_id', $evrIds)
            ->selectRaw('evr_activity_id, count(*) as total')->groupBy('evr_activity_id')->pluck('total', 'evr_activity_id');
        $arrived = CheckIn::whereIn('evr_activity_id', $evrIds)
            ->selectRaw('evr_activity_id, count(*) as total')->groupBy('evr_activity_id')->pluck('total', 'evr_activity_id');

        return collect($evrIds)->mapWithKeys(fn ($id) => [$id => [
            'expected' => (int) ($expected[$id] ?? 0),
            'checked_in' => (int) ($arrived[$id] ?? 0),
        ]])->all();
    }

    /**
     * Everyone expected at an outing, with whether they already showed up.
     *
     * @return list<array<string, mixed>>
     */
    private function roster(int $evrId): array
    {
        return $this->activeAssignments()->where('evr_activity_id', $evrId)
            ->with(['participant:id,registration_id,full_name,document_type,document_number,whatsapp,meal_type,allergies', 'checkIn.user:id,name'])
            ->get()
            ->sortBy(fn (RegistrationActivity $a) => mb_strtolower($a->participant->full_name))
            ->map(fn (RegistrationActivity $a) => [
                'participant' => $a->participant->id,
                'activity' => $a->id,
                'name' => $a->participant->full_name,
                'document' => trim("{$a->participant->document_type} {$a->participant->document_number}") ?: null,
                'whatsapp' => $a->participant->whatsapp,
                'meal_type' => $a->participant->meal_type,
                'allergies' => $a->participant->allergies,
                'check_in' => ParticipantRecord::checkInData($a->checkIn),
            ])
            ->values()
            ->all();
    }

    /**
     * @return Builder<RegistrationActivity>
     */
    private function activeAssignments(): Builder
    {
        return RegistrationActivity::query()
            ->whereIn('status', RegistrationActivity::ACTIVE_STATUSES)
            ->whereHas('participant.registration', fn (Builder $r) => $r->whereIn('status', Registration::PAID_STATUSES));
    }

    /**
     * Paid participants with an upcoming activity whose QR has not gone out yet.
     *
     * @return Builder<RegistrationParticipant>
     */
    private function pendingQrQuery(): Builder
    {
        return RegistrationParticipant::query()
            ->whereNull('qr_sent_at')
            ->where(fn (Builder $q) => $q->whereNull('qr_queued_at')->orWhere('qr_queued_at', '<', now()->subDay()))
            ->whereHas('registration', fn (Builder $r) => $r->whereIn('status', Registration::PAID_STATUSES))
            ->whereHas('activities', fn (Builder $a) => $a->whereIn('status', RegistrationActivity::ACTIVE_STATUSES)->whereDate('event_date', '>=', CheckInDesk::today()));
    }

    /**
     * @return array{pending: int, queued: int, sent: int, per_hour: int}
     */
    private function qrStats(): array
    {
        return [
            'pending' => $this->pendingQrQuery()->count(),
            'queued' => RegistrationParticipant::whereNull('qr_sent_at')->where('qr_queued_at', '>=', now()->subDay())->count(),
            'per_hour' => (int) config('crm.qr_emails_per_hour'),
            'sent' => RegistrationParticipant::whereNotNull('qr_sent_at')->count(),
        ];
    }
}
