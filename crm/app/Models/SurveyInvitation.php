<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

/**
 * One person's survey: who it went to, whether it arrived, and their answers.
 */
#[Fillable([
    'survey_id', 'registration_participant_id', 'person_key', 'full_name', 'email', 'token',
    'queued_at', 'sent_at', 'failed_at', 'error', 'opened_at', 'responded_at', 'answers',
])]
class SurveyInvitation extends Model
{
    protected static function booted(): void
    {
        static::creating(function (self $invitation) {
            $invitation->token ??= Str::random(40);
        });
    }

    protected function casts(): array
    {
        return [
            'queued_at' => 'datetime',
            'sent_at' => 'datetime',
            'failed_at' => 'datetime',
            'opened_at' => 'datetime',
            'responded_at' => 'datetime',
            'answers' => 'array',
        ];
    }

    public function survey(): BelongsTo
    {
        return $this->belongsTo(Survey::class);
    }

    public function participant(): BelongsTo
    {
        return $this->belongsTo(RegistrationParticipant::class, 'registration_participant_id');
    }

    public function url(): string
    {
        return route('surveys.public.show', $this->token);
    }

    /** Queued in the last day and not sent or failed yet. */
    public function isQueued(): bool
    {
        return ! $this->sent_at && ! $this->failed_at && (bool) $this->queued_at?->gt(now()->subDay());
    }
}
