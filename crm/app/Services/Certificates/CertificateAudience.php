<?php

namespace App\Services\Certificates;

use App\Models\Certificate;
use App\Models\CertificateRecipient;
use App\Models\Registration;
use App\Models\RegistrationActivity;
use App\Models\RegistrationParticipant;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * Who a certificate is for: people with a paid, active seat in its activity, and by default only those
 * checked in at the entrance. A person who bought the activity twice still gets one certificate.
 */
class CertificateAudience
{
    /**
     * Everyone with a paid seat, marking who qualifies.
     *
     * @return Collection<int, array{person_key: string, participant: RegistrationParticipant, checked_in: bool, checked_in_at: string|null, eligible: bool, email: string|null}>
     */
    public function people(Certificate $certificate): Collection
    {
        return RegistrationActivity::query()
            ->where('evr_activity_id', $certificate->evr_activity_id)
            ->whereIn('status', RegistrationActivity::ACTIVE_STATUSES)
            ->whereHas('participant.registration', fn (Builder $r) => $r->whereIn('status', Registration::PAID_STATUSES))
            ->with(['participant.registration:id,status,billing', 'checkIn:id,registration_activity_id,checked_in_at'])
            ->get()
            ->groupBy(fn (RegistrationActivity $a) => self::personKey($a->participant))
            ->map(function (Collection $seats, string $key) use ($certificate) {
                // The newest row carries the most recent name and email the plugin has for the person.
                $participant = $seats->map->participant->sortByDesc('id')->first();
                $checkIn = $seats->map->checkIn->filter()->sortBy('checked_in_at')->first();

                return [
                    'person_key' => $key,
                    'participant' => $participant,
                    'checked_in' => $checkIn !== null,
                    'checked_in_at' => $checkIn?->checked_in_at->toIso8601String(),
                    'eligible' => $checkIn !== null || ! $certificate->require_check_in,
                    'email' => $participant->qrEmail(),
                ];
            })
            ->sortBy(fn (array $p) => mb_strtolower($p['participant']->full_name))
            ->values();
    }

    /**
     * Creates a recipient (with its verification code) for every qualifying person who has none yet,
     * refreshes name and email of the ones not sent, and drops unsent ones who no longer qualify.
     *
     * @return Collection<int, CertificateRecipient> Recipients of qualifying people.
     */
    public function sync(Certificate $certificate): Collection
    {
        $people = $this->people($certificate)->where('eligible', true)->keyBy('person_key');
        $existing = $certificate->recipients()->get()->keyBy('person_key');

        $certificate->recipients()->whereNull('sent_at')->whereNotIn('person_key', $people->keys()->all())->delete();

        return $people->map(function (array $person, string $key) use ($certificate, $existing) {
            $participant = $person['participant'];
            $recipient = $existing->get($key) ?? new CertificateRecipient(['certificate_id' => $certificate->id, 'person_key' => $key]);

            // What was already sent stays as printed; the rest follows the plugin.
            if (! $recipient->sent_at) {
                $recipient->fill([
                    'registration_participant_id' => $participant->id,
                    'full_name' => $participant->full_name,
                    'document_number' => $participant->document_number,
                    'email' => $person['email'],
                ])->save();
            }

            return $recipient;
        })->values();
    }

    public static function personKey(RegistrationParticipant $participant): string
    {
        return $participant->evr_participant_id > 0 ? "evr{$participant->evr_participant_id}" : "row{$participant->id}";
    }
}
