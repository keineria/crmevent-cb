<?php

namespace App\Services\Campaigns;

use App\Models\Contact;
use App\Models\ContactEdition;
use App\Models\EventActivity;
use App\Models\Registration;
use App\Models\RegistrationActivity;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * Who a campaign goes to. Every filter narrows the list, and it always stops at contacts
 * who may receive broadcasts (consent given, not unsubscribed).
 *
 * Keys: tipos (asistentes|interesados|suscriptores, any of), anios (took part in any of these fairs),
 * min_visitas (fairs attended), compro (bought in this edition), no_compro (has not bought in this
 * edition yet), actividades (bought any of these plugin activities), pais (colombia|internacional).
 */
class Segment
{
    public const TYPES = ['asistentes' => 'is_attendee', 'interesados' => 'is_interested', 'suscriptores' => 'is_subscriber'];

    /**
     * Validation rules for a segment sent as `segment`.
     *
     * @return array<string, mixed>
     */
    public static function rules(): array
    {
        return [
            'segment' => ['nullable', 'array'],
            'segment.tipos' => ['nullable', 'array'],
            'segment.tipos.*' => ['string', 'in:'.implode(',', array_keys(self::TYPES))],
            'segment.anios' => ['nullable', 'array'],
            'segment.anios.*' => ['integer', 'between:2000,2100'],
            'segment.min_visitas' => ['nullable', 'integer', 'between:1,50'],
            'segment.compro' => ['nullable', 'integer', 'between:2000,2100'],
            'segment.no_compro' => ['nullable', 'integer', 'between:2000,2100'],
            'segment.actividades' => ['nullable', 'array'],
            'segment.actividades.*' => ['integer'],
            'segment.pais' => ['nullable', 'in:colombia,internacional'],
            'segment.productos' => ['nullable', 'array'],
            'segment.productos.*' => ['string', 'in:'.implode(',', array_keys(Contact::PRODUCTS))],
            'segment.min_estrellas' => ['nullable', 'integer', 'between:1,5'],
            'segment.usuario' => ['nullable', 'in:'.implode(',', array_keys(Contact::USER_LABELS))],
        ];
    }

    /**
     * Drops empty filters so stored segments stay small and comparable.
     *
     * @param  array<string, mixed>|null  $segment
     * @return array<string, mixed>
     */
    public static function clean(?array $segment): array
    {
        $segment ??= [];
        $clean = [];

        foreach (['tipos', 'anios', 'actividades', 'productos'] as $list) {
            $values = array_values(array_unique(array_filter((array) ($segment[$list] ?? []), fn ($v) => $v !== null && $v !== '')));
            if ($values) {
                $clean[$list] = in_array($list, ['tipos', 'productos'], true) ? $values : array_map('intval', $values);
            }
        }
        foreach (['min_visitas', 'compro', 'no_compro', 'min_estrellas'] as $int) {
            if (! empty($segment[$int])) {
                $clean[$int] = (int) $segment[$int];
            }
        }
        foreach (['pais', 'usuario'] as $string) {
            if (! empty($segment[$string])) {
                $clean[$string] = $segment[$string];
            }
        }

        return $clean;
    }

    /**
     * @param  array<string, mixed>|null  $segment
     * @return Builder<Contact>
     */
    public static function query(?array $segment): Builder
    {
        $s = self::clean($segment);
        $paid = fn (Builder $r) => $r->whereIn('status', Registration::PAID_STATUSES);

        return Contact::query()
            ->mailable()
            ->when($s['tipos'] ?? null, fn (Builder $q, array $types) => $q->where(function (Builder $q) use ($types) {
                foreach ($types as $type) {
                    $q->orWhere(self::TYPES[$type], true);
                }
            }))
            ->when($s['anios'] ?? null, fn (Builder $q, array $years) => $q->whereHas('editions', fn (Builder $e) => $e->whereIn('year', $years)))
            ->when($s['min_visitas'] ?? null, fn (Builder $q, int $min) => $q->whereHas(
                'editions',
                fn (Builder $e) => $e->where('role', ContactEdition::ROLE_ATTENDEE),
                '>=',
                $min,
            ))
            ->when($s['compro'] ?? null, fn (Builder $q, int $year) => $q->whereHas(
                'registrationParticipants.registration',
                fn (Builder $r) => $paid($r)->where('edition_year', $year),
            ))
            ->when($s['no_compro'] ?? null, fn (Builder $q, int $year) => $q->whereDoesntHave(
                'registrationParticipants.registration',
                fn (Builder $r) => $paid($r)->where('edition_year', $year),
            ))
            ->when($s['actividades'] ?? null, fn (Builder $q, array $ids) => $q->whereHas(
                'registrationParticipants',
                fn (Builder $p) => $p->whereHas('registration', $paid)->whereHas(
                    'activities',
                    fn (Builder $a) => $a->whereIn('evr_activity_id', $ids)->whereIn('status', RegistrationActivity::ACTIVE_STATUSES),
                ),
            ))
            ->when($s['productos'] ?? null, fn (Builder $q, array $products) => $q->where(function (Builder $q) use ($products) {
                foreach ($products as $product) {
                    $q->orWhereJsonContains('preferred_products', $product);
                }
            }))
            ->when($s['min_estrellas'] ?? null, fn (Builder $q, int $stars) => $q->where('engagement_stars', '>=', $stars))
            ->when($s['usuario'] ?? null, fn (Builder $q, string $type) => $q->ofUserType($type))
            ->when($s['pais'] ?? null, fn (Builder $q, string $country) => $country === 'colombia'
                ? $q->where(fn (Builder $c) => $c->whereNull('country')->orWhere('country', '')->orWhere('country', 'like', 'colombia%'))
                : $q->whereNotNull('country')->where('country', '!=', '')->where('country', 'not like', 'colombia%'));
    }

    /**
     * One line per filter, for lists and the confirmation dialog.
     *
     * @param  array<string, mixed>|null  $segment
     * @return list<string>
     */
    public static function describe(?array $segment): array
    {
        $s = self::clean($segment);
        $lines = [];

        if ($s['tipos'] ?? null) {
            $lines[] = 'Tipo: '.implode(' o ', $s['tipos']);
        }
        if ($s['anios'] ?? null) {
            $lines[] = 'Estuvieron en '.implode(', ', $s['anios']);
        }
        if ($s['min_visitas'] ?? null) {
            $lines[] = "Asistieron a {$s['min_visitas']} o más ferias";
        }
        if ($s['compro'] ?? null) {
            $lines[] = "Ya compraron en {$s['compro']}";
        }
        if ($s['no_compro'] ?? null) {
            $lines[] = "Aún no compran en {$s['no_compro']}";
        }
        if ($s['actividades'] ?? null) {
            $names = EventActivity::whereIn('evr_id', $s['actividades'])->pluck('name');
            $lines[] = 'Inscritos en: '.($names->isNotEmpty() ? $names->implode(', ') : count($s['actividades']).' actividades');
        }
        if ($s['pais'] ?? null) {
            $lines[] = $s['pais'] === 'colombia' ? 'Solo Colombia' : 'Solo fuera de Colombia';
        }
        if ($s['productos'] ?? null) {
            $lines[] = 'Producto preferido: '.implode(' o ', array_map(fn ($p) => Contact::PRODUCTS[$p] ?? $p, $s['productos']));
        }
        if ($s['min_estrellas'] ?? null) {
            $lines[] = "{$s['min_estrellas']} estrellas o más";
        }
        if ($s['usuario'] ?? null) {
            $lines[] = $s['usuario'] === Contact::USER_CLIENT ? 'Solo clientes (ya compraron)' : 'Solo suscriptores (aún no compran)';
        }

        return $lines ?: ['Todos los contactos activos'];
    }

    /**
     * Choices for the segment builder.
     *
     * @return array{years: Collection<int, int>, activities: Collection<int, array<string, mixed>>, products: array<string, string>}
     */
    public static function options(): array
    {
        $years = ContactEdition::query()->distinct()->pluck('year')
            ->merge(Registration::query()->whereNotNull('edition_year')->distinct()->pluck('edition_year'))
            ->map(fn ($y) => (int) $y)->unique()->sortDesc()->values();

        $activities = EventActivity::query()
            ->orderByDesc('event_date')->orderBy('name')
            ->get(['evr_id', 'name', 'type', 'event_date'])
            ->map(fn (EventActivity $a) => [
                'id' => $a->evr_id,
                'name' => $a->name,
                'type' => $a->type,
                'date' => $a->event_date?->toDateString(),
            ])->values();

        return ['years' => $years, 'activities' => $activities, 'products' => Contact::PRODUCTS];
    }
}
