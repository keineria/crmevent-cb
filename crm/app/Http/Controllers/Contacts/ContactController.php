<?php

namespace App\Http\Controllers\Contacts;

use App\Http\Controllers\Controller;
use App\Models\Contact;
use App\Models\ContactEdition;
use App\Models\Registration;
use App\Support\Roles;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class ContactController extends Controller
{
    private const TYPES = ['asistentes' => 'is_attendee', 'interesados' => 'is_interested', 'suscriptores' => 'is_subscriber'];

    public function index(Request $request): Response
    {
        $filters = $request->validate([
            'q' => ['nullable', 'string', 'max:120'],
            'tipo' => ['nullable', 'in:'.implode(',', array_keys(self::TYPES))],
            'estado' => ['nullable', Rule::in(array_keys(Contact::STATUS_LABELS))],
            'usuario' => ['nullable', Rule::in(array_keys(Contact::USER_LABELS))],
            'producto' => ['nullable', Rule::in(array_keys(Contact::PRODUCTS))],
            'estrellas' => ['nullable', 'integer', 'between:1,5'],
            'anio' => ['nullable', 'integer', 'between:2000,2100'],
        ]);

        $contacts = Contact::query()
            ->when($filters['q'] ?? null, function (Builder $query, string $term) {
                $like = "%{$term}%";
                $query->where(fn (Builder $q) => $q
                    ->where('first_name', 'like', $like)
                    ->orWhere('last_name', 'like', $like)
                    ->orWhere('email', 'like', $like)
                    ->orWhere('phone', 'like', $like)
                    ->orWhere('document_number', 'like', $like)
                    ->orWhere('organization', 'like', $like));
            })
            ->when($filters['tipo'] ?? null, fn (Builder $q, string $type) => $q->where(self::TYPES[$type], true))
            ->when($filters['estado'] ?? null, fn (Builder $q, string $status) => $status === Contact::STATUS_ACTIVE
                ? $q->whereNull('unsubscribed_at')
                : $q->whereNotNull('unsubscribed_at'))
            ->when($filters['usuario'] ?? null, fn (Builder $q, string $type) => $q->userType($type))
            ->when($filters['producto'] ?? null, fn (Builder $q, string $product) => $q->whereJsonContains('preferred_products', $product))
            ->when($filters['estrellas'] ?? null, fn (Builder $q, int $stars) => $q->where('engagement_stars', '>=', $stars))
            ->when($filters['anio'] ?? null, fn (Builder $q, int $year) => $q->whereHas('editions', fn (Builder $e) => $e->where('year', $year)))
            ->with('editions:id,contact_id,year,role')
            ->withHistory()
            ->orderByDesc('last_purchase_at')
            ->orderByDesc('last_year')
            ->orderByDesc('updated_at')
            ->paginate(25)
            ->withQueryString()
            ->through(fn (Contact $c) => [
                'id' => $c->id,
                'name' => $c->fullName() ?: null,
                'email' => $c->email,
                'phone' => $c->phone,
                'location' => collect([$c->city, $c->country])->filter()->implode(', ') ?: null,
                'organization' => $c->organization,
                'is_attendee' => $c->is_attendee,
                'is_interested' => $c->is_interested,
                'is_subscriber' => $c->is_subscriber,
                'status' => $c->status(),
                'user_type' => $c->userType(),
                'stars' => $c->engagement_stars,
                'products' => $c->productLabels(),
                'years' => $c->editions->pluck('year')->unique()->values(),
                'visits' => (int) $c->visits,
                'last_purchase_at' => $c->last_purchase_at ? Carbon::parse($c->last_purchase_at, 'UTC')->toIso8601String() : null,
            ]);

        return Inertia::render('contacts/index', [
            'contacts' => $contacts,
            'filters' => (object) $filters,
            'stats' => [
                'total' => Contact::count(),
                'attendees' => Contact::where('is_attendee', true)->count(),
                'interested' => Contact::where('is_interested', true)->count(),
                'subscribers' => Contact::where('is_subscriber', true)->count(),
                'clients' => Contact::userType(Contact::USER_CLIENT)->count(),
                'active' => Contact::whereNull('unsubscribed_at')->count(),
                'unsubscribed' => Contact::whereNotNull('unsubscribed_at')->count(),
            ],
            'products' => Contact::PRODUCTS,
            'years' => fn () => ContactEdition::query()->distinct()->orderByDesc('year')->pluck('year'),
            'formEndpoint' => route('api.subscribe'),
            'canImport' => $request->user()->hasAnyRole(Roles::CONTACT_IMPORTERS),
            'canEdit' => $request->user()->hasAnyRole(Roles::CONTACT_EDITORS),
        ]);
    }

    public function show(Request $request, Contact $contact): Response
    {
        $contact->load(['editions', 'registrationParticipants.registration', 'registrationParticipants.activities']);

        return Inertia::render('contacts/show', [
            'contact' => [
                'id' => $contact->id,
                'name' => $contact->fullName() ?: null,
                'email' => $contact->email,
                'phone' => $contact->phone,
                'document' => trim("{$contact->document_type} {$contact->document_number}") ?: null,
                'gender' => $contact->gender,
                'organization' => $contact->organization,
                'location' => collect([$contact->city, $contact->state, $contact->country])->filter()->implode(', ') ?: null,
                'notes' => $contact->notes,
                'source' => $contact->source,
                'is_attendee' => $contact->is_attendee,
                'is_interested' => $contact->is_interested,
                'is_subscriber' => $contact->is_subscriber,
                'status' => $contact->status(),
                'user_type' => $contact->userType(),
                'awaiting_confirmation' => $contact->awaitingConfirmation(),
                'products' => $contact->productLabels(),
                'products_manual' => $contact->preferred_products_manual,
                'profile' => $contact->profile,
                'language' => $contact->language,
                'referral_source' => $contact->referral_source,
                'stars' => $contact->engagement_stars,
                'emails_sent' => $contact->emails_sent,
                'emails_opened' => $contact->emails_opened,
                'emails_clicked' => $contact->emails_clicked,
                'consent_source' => $contact->consent_source,
                'consent_at' => $contact->consent_at?->toIso8601String(),
                'unsubscribed_at' => $contact->unsubscribed_at?->toIso8601String(),
                'created_at' => $contact->created_at->toIso8601String(),
            ],
            'editions' => $contact->editions->map(fn (ContactEdition $e) => [
                'year' => $e->year,
                'role' => $e->role,
                'details' => (object) ($e->details ?? []),
            ]),
            'registrations' => $contact->registrationParticipants->sortByDesc(fn ($p) => $p->registration->ordered_at)->values()->map(fn ($p) => [
                'order' => $p->registration->number,
                'status' => $p->registration->status,
                'status_label' => Registration::STATUS_LABELS[$p->registration->status] ?? $p->registration->status,
                'ordered_at' => $p->registration->ordered_at?->toIso8601String(),
                'shirt_size' => $p->shirt_size,
                'meal_type' => $p->meal_type,
                'allergies' => $p->allergies,
                'activities' => $p->activities->map(fn ($a) => [
                    'name' => $a->name,
                    'type' => $a->type,
                    'date' => $a->event_date?->toDateString(),
                    'status' => $a->status,
                    'total' => (float) $a->total,
                ]),
            ]),
            'canEdit' => $request->user()->hasAnyRole(Roles::CONTACT_EDITORS),
        ]);
    }
}
