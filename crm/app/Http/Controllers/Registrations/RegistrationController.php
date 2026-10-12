<?php

namespace App\Http\Controllers\Registrations;

use App\Http\Controllers\Controller;
use App\Models\EventActivity;
use App\Models\PluginConnection;
use App\Models\Registration;
use App\Models\RegistrationActivity;
use App\Models\RegistrationParticipant;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class RegistrationController extends Controller
{
    private const STATUS_GROUPS = [
        'pagadas' => Registration::PAID_STATUSES,
        'pendientes' => ['pending', 'on-hold', 'checkout-draft'],
        'canceladas' => ['cancelled', 'refunded', 'failed'],
    ];

    public function index(Request $request): Response
    {
        $filters = $request->validate([
            'q' => ['nullable', 'string', 'max:120'],
            'estado' => ['nullable', 'in:'.implode(',', array_keys(self::STATUS_GROUPS))],
            'actividad' => ['nullable', 'integer'],
            'tipo' => ['nullable', 'in:'.implode(',', array_keys(EventActivity::TYPE_LABELS))],
        ]);

        $participants = RegistrationParticipant::query()
            ->with(['registration:id,wc_order_id,number,status,total,ordered_at,billing', 'activities:id,registration_participant_id,evr_activity_id,name,type,event_date,start_time,status'])
            ->when($filters['q'] ?? null, function (Builder $query, string $term) {
                $like = "%{$term}%";
                $query->where(fn (Builder $q) => $q
                    ->where('full_name', 'like', $like)
                    ->orWhere('email', 'like', $like)
                    ->orWhere('document_number', 'like', $like)
                    ->orWhereHas('registration', fn (Builder $r) => $r->where('number', 'like', $like)));
            })
            ->when($filters['estado'] ?? null, fn (Builder $q, string $s) => $q->whereHas('registration', fn (Builder $r) => $r->whereIn('status', self::STATUS_GROUPS[$s])))
            ->when($filters['actividad'] ?? null, fn (Builder $q, int $id) => $q->whereHas('activities', fn (Builder $a) => $a->where('evr_activity_id', $id)->whereIn('status', RegistrationActivity::ACTIVE_STATUSES)))
            ->when($filters['tipo'] ?? null, fn (Builder $q, string $t) => $q->whereHas('activities', fn (Builder $a) => $a->where('type', $t)->whereIn('status', RegistrationActivity::ACTIVE_STATUSES)))
            ->orderByDesc(Registration::select('ordered_at')->whereColumn('registrations.id', 'registration_participants.registration_id'))
            ->orderByDesc('id')
            ->paginate(25)
            ->withQueryString()
            ->through(fn (RegistrationParticipant $p) => [
                'id' => $p->id,
                'contact_id' => $p->contact_id,
                'name' => $p->full_name,
                'email' => $p->email,
                'document' => trim("{$p->document_type} {$p->document_number}") ?: null,
                'shirt_size' => $p->shirt_size,
                'meal_type' => $p->meal_type,
                'order' => [
                    'number' => $p->registration->number,
                    'status' => $p->registration->status,
                    'ordered_at' => $p->registration->ordered_at?->toIso8601String(),
                    'city' => $p->registration->billing['city'] ?? null,
                ],
                'activities' => $p->activities->map(fn (RegistrationActivity $a) => [
                    'name' => $a->name,
                    'type' => $a->type,
                    'date' => $a->event_date?->toDateString(),
                    'status' => $a->status,
                ]),
            ]);

        $paid = Registration::whereIn('status', Registration::PAID_STATUSES);

        return Inertia::render('registrations/index', [
            'participants' => $participants,
            'filters' => (object) $filters,
            'stats' => [
                'paid_orders' => (clone $paid)->count(),
                'participants' => RegistrationParticipant::whereHas('registration', fn (Builder $r) => $r->whereIn('status', Registration::PAID_STATUSES))->count(),
                'revenue' => (float) (clone $paid)->sum('total'),
                'pending_orders' => Registration::whereIn('status', self::STATUS_GROUPS['pendientes'])->count(),
            ],
            'activities' => EventActivity::orderBy('type')->orderBy('event_date')->orderBy('name')->get(['evr_id', 'name', 'type', 'event_date']),
            'types' => EventActivity::TYPE_LABELS,
            'statusLabels' => Registration::STATUS_LABELS,
            'lastSync' => PluginConnection::query()->value('last_synced_at'),
        ]);
    }

    public function activities(): Response
    {
        return Inertia::render('registrations/activities', [
            'activities' => EventActivity::orderBy('event_date')->orderBy('start_time')->orderBy('name')->get()->map(fn (EventActivity $a) => [
                'id' => $a->evr_id,
                'name' => $a->name,
                'type' => $a->type,
                'destination' => $a->destination,
                'date' => $a->event_date?->toDateString(),
                'start_time' => $a->start_time ? substr($a->start_time, 0, 5) : null,
                'price' => (float) $a->price,
                'capacity' => $a->capacity_total,
                'confirmed' => $a->confirmed_count,
                'reserved' => $a->reserved_count,
                'status' => $a->status,
            ]),
            'types' => EventActivity::TYPE_LABELS,
            'lastSync' => PluginConnection::query()->value('last_synced_at'),
        ]);
    }
}
