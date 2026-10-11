<?php

namespace App\Http\Controllers\Contacts;

use App\Http\Controllers\Controller;
use App\Models\Contact;
use App\Models\ContactEdition;
use App\Support\Roles;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
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
            'estado' => ['nullable', 'in:acepta,baja,pendiente,sin_consentimiento'],
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
            ->when($filters['estado'] ?? null, fn (Builder $q, string $status) => match ($status) {
                'acepta' => $q->mailable(),
                'baja' => $q->whereNotNull('unsubscribed_at'),
                'pendiente' => $q->whereNotNull('subscription_requested_at')->where('is_subscriber', false)->whereNull('unsubscribed_at'),
                'sin_consentimiento' => $q->where('marketing_consent', false)->whereNull('unsubscribed_at'),
            })
            ->when($filters['anio'] ?? null, fn (Builder $q, int $year) => $q->whereHas('editions', fn (Builder $e) => $e->where('year', $year)))
            ->with('editions:id,contact_id,year,role')
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
                'status' => $this->status($c),
                'years' => $c->editions->pluck('year')->unique()->values(),
            ]);

        return Inertia::render('contacts/index', [
            'contacts' => $contacts,
            'filters' => (object) $filters,
            'stats' => [
                'total' => Contact::count(),
                'attendees' => Contact::where('is_attendee', true)->count(),
                'interested' => Contact::where('is_interested', true)->count(),
                'subscribers' => Contact::where('is_subscriber', true)->count(),
                'mailable' => Contact::mailable()->count(),
            ],
            'years' => fn () => ContactEdition::query()->distinct()->orderByDesc('year')->pluck('year'),
            'formEndpoint' => route('api.subscribe'),
            'canImport' => $request->user()->hasAnyRole(Roles::CONTACT_IMPORTERS),
        ]);
    }

    private function status(Contact $contact): string
    {
        return match (true) {
            $contact->unsubscribed_at !== null => 'baja',
            $contact->marketing_consent && $contact->email !== null => 'acepta',
            $contact->subscription_requested_at !== null && ! $contact->is_subscriber => 'pendiente',
            default => 'sin_consentimiento',
        };
    }
}
