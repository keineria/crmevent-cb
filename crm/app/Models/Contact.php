<?php

namespace App\Models;

use Database\Factories\ContactFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

#[Fillable([
    'first_name', 'last_name', 'gender', 'email', 'phone', 'document_type', 'document_number', 'country', 'state', 'city', 'organization', 'notes',
    'preferred_products', 'preferred_products_manual', 'profile', 'language', 'referral_source',
    'is_attendee', 'is_interested', 'is_subscriber', 'source',
    'marketing_consent', 'consent_at', 'consent_source', 'consent_ip',
    'subscription_requested_at', 'subscribed_at', 'unsubscribed_at',
])]
class Contact extends Model
{
    /** @use HasFactory<ContactFactory> */
    use HasFactory;

    public const SOURCE_IMPORT = 'import';

    public const SOURCE_WEB = 'web';

    public const SOURCE_MANUAL = 'manual';

    public const SOURCE_PLUGIN = 'plugin';

    /** Profile fields an import may fill; it never overwrites a value already saved. */
    public const PROFILE_FIELDS = [
        'first_name', 'last_name', 'gender', 'phone', 'document_type', 'document_number', 'country', 'state', 'city', 'organization', 'notes',
        'profile', 'language', 'referral_source',
    ];

    /** What a contact buys or would buy: key => label. Derived from purchases unless staff set it by hand. */
    public const PRODUCTS = [
        'conferencias' => 'Conferencias',
        'salidas' => 'Salidas',
        'talleres' => 'Talleres',
        'otras' => 'Otras actividades',
        'stand' => 'Stand',
    ];

    /** Suggested values for the profile fields; any other text is accepted too. */
    public const PROFILES = ['Aficionado a las aves', 'Fotógrafo', 'Guía de aviturismo', 'Investigador o académico', 'Estudiante', 'Operador turístico o empresa', 'Prensa', 'Otro'];

    public const LANGUAGES = ['Español', 'Inglés', 'Portugués', 'Otro'];

    public const REFERRAL_SOURCES = ['Redes sociales', 'Recomendación', 'Correo de la feria', 'Sitio web', 'Prensa', 'Ferias anteriores', 'Otro'];

    /** The only two states a contact shows: may receive broadcasts, or unsubscribed. */
    public const STATUS_ACTIVE = 'activo';

    public const STATUS_UNSUBSCRIBED = 'desinscrito';

    public const STATUS_LABELS = [self::STATUS_ACTIVE => 'Activo', self::STATUS_UNSUBSCRIBED => 'Desinscrito'];

    protected function casts(): array
    {
        return [
            'preferred_products' => 'array',
            'preferred_products_manual' => 'boolean',
            'engagement_stars' => 'integer',
            'is_attendee' => 'boolean',
            'is_interested' => 'boolean',
            'is_subscriber' => 'boolean',
            'marketing_consent' => 'boolean',
            'consent_at' => 'datetime',
            'subscription_requested_at' => 'datetime',
            'subscribed_at' => 'datetime',
            'unsubscribed_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (Contact $contact) {
            $contact->unsubscribe_token ??= Str::random(48);
        });

        static::saving(function (Contact $contact) {
            if ($contact->email !== null) {
                $contact->email = Str::lower(trim($contact->email)) ?: null;
            }
        });
    }

    public function editions(): HasMany
    {
        return $this->hasMany(ContactEdition::class)->orderByDesc('year');
    }

    public function registrationParticipants(): HasMany
    {
        return $this->hasMany(RegistrationParticipant::class);
    }

    /**
     * Adds visits (distinct fair years attended), last_year attended and last_purchase_at (latest paid plugin order).
     */
    public function scopeWithHistory(Builder $query): void
    {
        $attended = fn ($q) => $q->where('role', ContactEdition::ROLE_ATTENDEE);

        $query
            ->withCount(['editions as visits' => fn ($q) => $attended($q)->select(DB::raw('count(distinct year)'))])
            ->withMax(['editions as last_year' => $attended], 'year')
            ->addSelect(['last_purchase_at' => Registration::query()
                ->selectRaw('max(registrations.ordered_at)')
                ->join('registration_participants as rp', 'rp.registration_id', '=', 'registrations.id')
                ->whereColumn('rp.contact_id', 'contacts.id')
                ->whereIn('registrations.status', Registration::PAID_STATUSES)]);
    }

    /**
     * Contacts that may receive broadcasts: they gave consent and have not unsubscribed.
     */
    public function scopeMailable(Builder $query): void
    {
        $query->whereNotNull('email')->where('marketing_consent', true)->whereNull('unsubscribed_at');
    }

    public function status(): string
    {
        return $this->unsubscribed_at === null ? self::STATUS_ACTIVE : self::STATUS_UNSUBSCRIBED;
    }

    /** Signed up on the website and has not clicked the confirmation link yet. */
    public function awaitingConfirmation(): bool
    {
        return $this->unsubscribed_at === null && ! $this->marketing_consent && $this->subscription_requested_at !== null && ! $this->is_subscriber;
    }

    /**
     * @return list<string>
     */
    public function productLabels(): array
    {
        return array_values(array_intersect_key(self::PRODUCTS, array_flip($this->preferred_products ?? [])));
    }

    public function fullName(): string
    {
        return trim("{$this->first_name} {$this->last_name}");
    }

    public function unsubscribeUrl(): string
    {
        return route('unsubscribe.show', $this->unsubscribe_token);
    }

    public function unsubscribe(): void
    {
        if ($this->unsubscribed_at) {
            return;
        }

        $this->forceFill([
            'unsubscribed_at' => now(),
            'marketing_consent' => false,
            'is_subscriber' => false,
        ])->save();
    }

    public function grantConsent(string $source, ?string $ip = null): void
    {
        $this->forceFill([
            'marketing_consent' => true,
            'consent_at' => now(),
            'consent_source' => $source,
            'consent_ip' => $ip,
            'unsubscribed_at' => null,
        ]);
    }
}
