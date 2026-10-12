<?php

namespace App\Services\CheckIn;

use App\Models\CheckIn;
use App\Models\RegistrationActivity;
use App\Models\RegistrationParticipant;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * Everything the desk needs about one person: their details, every order and activity, money and arrivals.
 * A person who bought twice has one plugin participant id and one row per order here.
 */
class ParticipantRecord
{
    public const DOCUMENT_TYPES = [
        'cc' => 'Cédula de ciudadanía',
        'ti' => 'Tarjeta de identidad',
        'ce' => 'Cédula de extranjería',
        'passport' => 'Pasaporte',
        'other' => 'Otro documento',
    ];

    /**
     * Every row of the same person in orders the desk can see, newest first.
     *
     * @return Collection<int, RegistrationParticipant>
     */
    public static function rows(RegistrationParticipant $participant): Collection
    {
        return RegistrationParticipant::query()
            ->when(
                $participant->evr_participant_id > 0,
                fn (Builder $q) => $q->where('evr_participant_id', $participant->evr_participant_id),
                fn (Builder $q) => $q->whereKey($participant->id),
            )
            ->whereHas('registration', fn (Builder $r) => $r->whereIn('status', CheckInDesk::VISIBLE_STATUSES))
            ->with(['registration', 'activities.checkIn.user:id,name'])
            ->get()
            ->sortByDesc(fn (RegistrationParticipant $p) => $p->registration->ordered_at?->getTimestamp() ?? $p->id)
            ->values();
    }

    /**
     * @param  list<string>  $types  Activity types this desk checks in.
     * @return array<string, mixed>|null
     */
    public static function build(RegistrationParticipant $participant, array $types, string $date): ?array
    {
        $rows = self::rows($participant);
        $latest = $rows->first();
        if (! $latest) {
            return null;
        }

        $paidActive = $rows->filter(fn (RegistrationParticipant $p) => $p->registration->isPaid())
            ->flatMap(fn (RegistrationParticipant $p) => $p->activities)
            ->filter(fn (RegistrationActivity $a) => in_array($a->status, RegistrationActivity::ACTIVE_STATUSES, true));

        return [
            'id' => $latest->id,
            'linked' => $latest->evr_participant_id > 0,
            'name' => $latest->full_name,
            'document_type' => $latest->document_type,
            'document_label' => self::DOCUMENT_TYPES[strtolower((string) $latest->document_type)] ?? $latest->document_type,
            'document_number' => $latest->document_number,
            'email' => $latest->email,
            'whatsapp' => $latest->whatsapp,
            'shirt_size' => $latest->shirt_size,
            'meal_type' => $latest->meal_type,
            'allergies' => $latest->allergies,
            'entity' => $rows->pluck('entity')->filter()->first(),
            'code' => $latest->checkin_code,
            'qr_sent_at' => $rows->pluck('qr_sent_at')->filter()->max()?->toIso8601String(),
            'totals' => [
                'price' => (float) $paidActive->sum('price'),
                'discount' => (float) $paidActive->sum('discount'),
                'total' => (float) $paidActive->sum('total'),
                'confirmed' => $paidActive->count(),
            ],
            'discounts' => $paidActive->filter(fn (RegistrationActivity $a) => (float) $a->discount > 0)
                ->groupBy(fn (RegistrationActivity $a) => $a->discount_label ?: 'Descuento')
                ->map(fn (Collection $items, string $label) => ['label' => $label, 'amount' => (float) $items->sum('discount')])
                ->values(),
            'orders' => $rows->map(fn (RegistrationParticipant $p) => [
                'participant' => $p->id,
                'number' => $p->registration->number,
                'status' => $p->registration->status,
                'paid' => $p->registration->isPaid(),
                'ordered_at' => $p->registration->ordered_at?->toIso8601String(),
                'registered_by' => $p->registered_by,
                'activities' => $p->activities->map(fn (RegistrationActivity $a) => [
                    'id' => $a->id,
                    'name' => $a->name,
                    'type' => $a->type,
                    'date' => $a->event_date?->toDateString(),
                    'start_time' => $a->start_time ? substr($a->start_time, 0, 5) : null,
                    'status' => $a->status,
                    'active' => in_array($a->status, RegistrationActivity::ACTIVE_STATUSES, true),
                    'price' => (float) $a->price,
                    'discount' => (float) $a->discount,
                    'total' => (float) $a->total,
                    'discount_label' => $a->discount_label,
                    'on_date' => $a->event_date?->toDateString() === $date,
                    'here' => in_array($a->type, $types, true),
                    'check_in' => self::checkInData($a->checkIn),
                ])->values(),
            ])->values(),
        ];
    }

    /**
     * @return array{id: int, at: string, by: string|null, method: string}|null
     */
    public static function checkInData(?CheckIn $checkIn): ?array
    {
        return $checkIn ? [
            'id' => $checkIn->id,
            'at' => $checkIn->checked_in_at->toIso8601String(),
            'by' => $checkIn->user?->name,
            'method' => $checkIn->method,
        ] : null;
    }
}
