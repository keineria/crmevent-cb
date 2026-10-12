<?php

namespace App\Http\Controllers\Contacts;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\Contact;
use App\Models\ContactEdition;
use App\Services\Contacts\ContactInsights;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Adds a contact by hand (someone missing from the Excel files) and edits one already in the CRM.
 */
class ContactFormController extends Controller
{
    private const FIELDS = [
        'first_name', 'last_name', 'email', 'phone', 'document_type', 'document_number', 'gender', 'country', 'state', 'city',
        'organization', 'notes', 'profile', 'language', 'referral_source',
    ];

    public function __construct(private ContactInsights $insights) {}

    public function create(): Response
    {
        return $this->form(null);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $this->validated($request, null);

        $contact = DB::transaction(function () use ($request, $data) {
            $contact = new Contact(['source' => Contact::SOURCE_MANUAL]);
            $this->fill($request, $contact, $data);

            if (! empty($data['edition_year'])) {
                ContactEdition::create([
                    'contact_id' => $contact->id,
                    'year' => $data['edition_year'],
                    'role' => $contact->is_attendee ? ContactEdition::ROLE_ATTENDEE : ContactEdition::ROLE_INTERESTED,
                    'details' => ['Fuente' => 'Alta manual'],
                ]);
                $this->insights->refresh($contact);
            }

            return $contact;
        });

        AuditLog::record('contacts.created', $contact, ['campos' => array_keys(array_filter($contact->only(self::FIELDS)))]);

        return to_route('contacts.show', $contact)->with('success', 'Contacto creado.');
    }

    public function edit(Contact $contact): Response
    {
        return $this->form($contact);
    }

    public function update(Request $request, Contact $contact): RedirectResponse
    {
        $data = $this->validated($request, $contact);
        $before = $contact->only([...self::FIELDS, 'preferred_products', 'is_attendee', 'is_interested', 'is_subscriber']) + ['estado' => $contact->status()];

        DB::transaction(fn () => $this->fill($request, $contact, $data));

        $after = $contact->only(array_keys($before)) + ['estado' => $contact->status()];
        $changed = array_keys(array_filter($after, fn ($value, $key) => $value != $before[$key], ARRAY_FILTER_USE_BOTH));
        if ($changed) {
            AuditLog::record('contacts.updated', $contact, ['campos' => $changed]);
        }

        return to_route('contacts.show', $contact)->with('success', 'Contacto actualizado.');
    }

    private function form(?Contact $contact): Response
    {
        return Inertia::render('contacts/form', [
            'contact' => $contact ? [
                'id' => $contact->id,
                ...array_map(fn ($v) => $v ?? '', $contact->only(self::FIELDS)),
                'is_attendee' => $contact->is_attendee,
                'is_interested' => $contact->is_interested,
                'is_subscriber' => $contact->is_subscriber,
                'products_auto' => ! $contact->preferred_products_manual,
                'preferred_products' => $contact->preferred_products ?? [],
                'status' => $contact->status(),
                'name' => $contact->fullName() ?: ($contact->email ?? 'Contacto'),
            ] : null,
            'options' => [
                'products' => Contact::PRODUCTS,
                'profiles' => Contact::PROFILES,
                'languages' => Contact::LANGUAGES,
                'referrals' => Contact::REFERRAL_SOURCES,
            ],
            'defaultYear' => (int) now()->year,
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function validated(Request $request, ?Contact $contact): array
    {
        $request->merge([
            'email' => $request->filled('email') ? Str::lower(trim((string) $request->input('email'))) : null,
            'document_number' => $request->filled('document_number')
                ? Str::upper(preg_replace('/[\s.\-]/', '', (string) $request->input('document_number')))
                : null,
            'phone' => $request->filled('phone') ? (preg_replace('/[^\d+]/', '', (string) $request->input('phone')) ?: null) : null,
        ]);

        $data = $request->validate([
            'first_name' => ['required', 'string', 'max:120'],
            'last_name' => ['nullable', 'string', 'max:120'],
            'email' => ['nullable', 'email', 'max:191'],
            'phone' => ['nullable', 'string', 'max:40'],
            'document_type' => ['nullable', 'string', 'max:30'],
            'document_number' => ['nullable', 'string', 'max:40'],
            'gender' => ['nullable', 'string', 'max:30'],
            'country' => ['nullable', 'string', 'max:80'],
            'state' => ['nullable', 'string', 'max:120'],
            'city' => ['nullable', 'string', 'max:120'],
            'organization' => ['nullable', 'string', 'max:191'],
            'notes' => ['nullable', 'string', 'max:2000'],
            'profile' => ['nullable', 'string', 'max:60'],
            'language' => ['nullable', 'string', 'max:30'],
            'referral_source' => ['nullable', 'string', 'max:80'],
            'is_attendee' => ['boolean'],
            'is_interested' => ['boolean'],
            'is_subscriber' => ['boolean'],
            'products_auto' => ['boolean'],
            'preferred_products' => ['array'],
            'preferred_products.*' => [Rule::in(array_keys(Contact::PRODUCTS))],
            'status' => ['required', Rule::in(array_keys(Contact::STATUS_LABELS))],
            'edition_year' => ['nullable', 'integer', 'between:2000,2100'],
        ], [
            'first_name.required' => 'Escribe el nombre.',
        ], [
            'first_name' => 'nombre', 'last_name' => 'apellidos', 'email' => 'correo', 'phone' => 'teléfono', 'document_number' => 'documento',
            'edition_year' => 'año de la feria',
        ]);

        if (empty($data['email']) && empty($data['document_number']) && empty($data['phone'])) {
            throw ValidationException::withMessages(['email' => 'Escribe al menos el correo, el documento o el teléfono.']);
        }

        // Never create a second record of someone already in the CRM: point to the one that exists.
        foreach (['email' => 'correo', 'document_number' => 'documento'] as $field => $label) {
            if (empty($data[$field])) {
                continue;
            }
            $existing = Contact::where($field, $data[$field])->when($contact, fn ($q) => $q->whereKeyNot($contact->id))->first();
            if ($existing) {
                $name = $existing->fullName() ?: "#{$existing->id}";
                throw ValidationException::withMessages([
                    $field => "Este {$label} ya es de «{$name}» en el CRM (contacto #{$existing->id}). Edita ese contacto en vez de crear otro.",
                ]);
            }
        }

        return $data;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function fill(Request $request, Contact $contact, array $data): void
    {
        foreach (self::FIELDS as $field) {
            $value = isset($data[$field]) ? trim((string) $data[$field]) : '';
            $contact->{$field} = $value === '' ? null : $value;
        }

        $contact->is_attendee = (bool) ($data['is_attendee'] ?? false);
        $contact->is_interested = (bool) ($data['is_interested'] ?? false);
        $contact->is_subscriber = (bool) ($data['is_subscriber'] ?? false);

        $auto = (bool) ($data['products_auto'] ?? true);
        $contact->preferred_products_manual = ! $auto;
        if (! $auto) {
            $contact->preferred_products = ContactInsights::sortProducts($data['preferred_products'] ?? []) ?: null;
        }

        $who = $request->user()->name;
        if ($data['status'] === Contact::STATUS_UNSUBSCRIBED) {
            if ($contact->unsubscribed_at === null) {
                $contact->forceFill(['unsubscribed_at' => now(), 'marketing_consent' => false, 'is_subscriber' => false]);
            }
        } elseif ($contact->unsubscribed_at !== null || (! $contact->marketing_consent && ! $contact->awaitingConfirmation())) {
            $contact->grantConsent($contact->exists ? "Activado a mano por {$who}" : "Alta manual por {$who}");
        }

        $contact->save();

        if ($auto) {
            $this->insights->refresh($contact);
        }
    }
}
