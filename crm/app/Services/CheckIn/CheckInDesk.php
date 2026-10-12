<?php

namespace App\Services\CheckIn;

use App\Models\AuditLog;
use App\Models\CheckIn;
use App\Models\Registration;
use App\Models\RegistrationActivity;
use App\Models\RegistrationParticipant;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Str;

/**
 * Finds participants at the door and records their arrival. Seats stay in the plugin: this never touches them.
 */
class CheckInDesk
{
    /** Orders shown at the desk: paid ones, plus pending ones so staff can tell the person what is missing. */
    public const VISIBLE_STATUSES = [...Registration::PAID_STATUSES, 'pending', 'on-hold'];

    private const MAX_RESULTS = 15;

    /**
     * One participant row per person (a person who bought twice appears once).
     *
     * @return array{method: string, participants: Collection<int, RegistrationParticipant>}
     */
    public function search(string $term): array
    {
        $found = $this->find($term);
        $found['participants'] = $found['participants']
            ->unique(fn (RegistrationParticipant $p) => $p->evr_participant_id > 0 ? "evr{$p->evr_participant_id}" : "row{$p->id}")
            ->take(self::MAX_RESULTS)
            ->values();

        return $found;
    }

    /**
     * @return array{method: string, participants: Collection<int, RegistrationParticipant>}
     */
    private function find(string $term): array
    {
        $term = trim($term);
        $query = RegistrationParticipant::query()
            ->whereHas('registration', fn (Builder $r) => $r->whereIn('status', self::VISIBLE_STATUSES))
            ->with('registration:id,number,status,ordered_at');

        if ($code = RegistrationParticipant::codeFrom($term)) {
            return ['method' => CheckIn::METHOD_QR, 'participants' => $query->where('checkin_code', $code)->get()];
        }

        $document = $this->normalizeDocument($term);
        if (strlen($document) >= 4 && preg_match('/\d{4,}/', $document)) {
            $byDocument = (clone $query)->whereRaw($this->documentSql().' = ?', [$document])->limit(self::MAX_RESULTS)->get();
            if ($byDocument->isNotEmpty()) {
                return ['method' => CheckIn::METHOD_DOCUMENT, 'participants' => $byDocument];
            }
        }

        if (mb_strlen($term) < 3) {
            return ['method' => CheckIn::METHOD_SEARCH, 'participants' => new Collection];
        }

        $like = "%{$term}%";
        $participants = $query
            ->where(fn (Builder $q) => $q
                ->where('full_name', 'like', $like)
                ->orWhere('email', 'like', $like)
                ->orWhereRaw($this->documentSql().' like ?', ['%'.$document.'%'])
                ->orWhereHas('registration', fn (Builder $r) => $r->where('number', $term)))
            ->orderBy('full_name')
            ->limit(self::MAX_RESULTS * 2)
            ->get();

        return ['method' => CheckIn::METHOD_SEARCH, 'participants' => $participants];
    }

    /**
     * Records the arrival to each given activity of this person (any of their orders).
     * Already checked-in activities are left as they were.
     *
     * @param  list<int>  $activityIds  registration_activities ids.
     * @param  list<string>  $types  Activity types this desk may check in.
     * @return array{created: int, already: int}
     */
    public function checkIn(RegistrationParticipant $participant, array $activityIds, array $types, string $method, User $user): array
    {
        $rows = ParticipantRecord::rows($participant)->keyBy('id');
        $activities = RegistrationActivity::whereIn('registration_participant_id', $rows->keys())
            ->whereIn('id', $activityIds)->with('checkIn')->get();

        if ($activities->count() !== count(array_unique($activityIds))) {
            throw new CheckInRefused('Alguna de las actividades no pertenece a esta persona.');
        }

        $result = ['created' => 0, 'already' => 0];
        $registered = [];

        foreach ($activities as $activity) {
            $registration = $rows[$activity->registration_participant_id]->registration;
            if (! $registration->isPaid()) {
                throw new CheckInRefused("El pedido #{$registration->number} no está pagado.");
            }
            if (! in_array($activity->type, $types, true)) {
                throw new CheckInRefused("«{$activity->name}» no se registra en este punto.");
            }
            if (! in_array($activity->status, RegistrationActivity::ACTIVE_STATUSES, true)) {
                throw new CheckInRefused("«{$activity->name}» ya no está activa en su inscripción.");
            }
        }

        foreach ($activities as $activity) {
            if ($activity->checkIn) {
                $result['already']++;

                continue;
            }

            try {
                CheckIn::create([
                    'registration_activity_id' => $activity->id,
                    'registration_participant_id' => $activity->registration_participant_id,
                    'evr_activity_id' => $activity->evr_activity_id,
                    'method' => $method,
                    'user_id' => $user->id,
                    'checked_in_at' => now(),
                ]);
                $result['created']++;
                $registered[] = $activity->name;
            } catch (UniqueConstraintViolationException) {
                // Another desk registered the same person a moment ago.
                $result['already']++;
            }
        }

        if ($registered) {
            AuditLog::record('checkin.created', $participant, ['actividades' => $registered, 'metodo' => $method], $user);
        }

        return $result;
    }

    public function undo(CheckIn $checkIn): void
    {
        AuditLog::record('checkin.undone', $checkIn->participant, [
            'actividad' => $checkIn->activity?->name,
            'registrado' => $checkIn->checked_in_at->toIso8601String(),
        ]);

        $checkIn->delete();
    }

    /**
     * Today's date where the fair happens.
     */
    public static function today(): string
    {
        return now(CheckIn::TIMEZONE)->toDateString();
    }

    private function normalizeDocument(string $value): string
    {
        return Str::upper(preg_replace('/[\s.\-]/', '', $value));
    }

    /**
     * The stored document number without dots, dashes or spaces, written so SQLite and MariaDB both run it.
     */
    private function documentSql(): string
    {
        return "UPPER(REPLACE(REPLACE(REPLACE(COALESCE(document_number, ''), '.', ''), '-', ''), ' ', ''))";
    }
}
