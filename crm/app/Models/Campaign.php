<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['name', 'subject', 'preheader', 'body', 'segment', 'status', 'scheduled_at', 'started_at', 'finished_at', 'created_by', 'updated_by'])]
class Campaign extends Model
{
    public const DRAFT = 'draft';

    public const SCHEDULED = 'scheduled';

    public const SENDING = 'sending';

    public const PAUSED = 'paused';

    public const SENT = 'sent';

    public const CANCELLED = 'cancelled';

    public const STATUS_LABELS = [
        self::DRAFT => 'Borrador',
        self::SCHEDULED => 'Programada',
        self::SENDING => 'Enviando',
        self::PAUSED => 'En pausa',
        self::SENT => 'Enviada',
        self::CANCELLED => 'Cancelada',
    ];

    protected function casts(): array
    {
        return [
            'segment' => 'array',
            'scheduled_at' => 'datetime',
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
        ];
    }

    public function recipients(): HasMany
    {
        return $this->hasMany(CampaignRecipient::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function isEditable(): bool
    {
        return in_array($this->status, [self::DRAFT, self::SCHEDULED], true);
    }

    /**
     * Adds recipients, sent, failed, skipped, pending, opened and clicked counts.
     *
     * @param  Builder<self>  $query
     */
    public function scopeWithStats($query): void
    {
        $query->withCount([
            'recipients',
            'recipients as sent_count' => fn ($q) => $q->where('status', CampaignRecipient::SENT),
            'recipients as failed_count' => fn ($q) => $q->where('status', CampaignRecipient::FAILED),
            'recipients as skipped_count' => fn ($q) => $q->where('status', CampaignRecipient::SKIPPED),
            'recipients as pending_count' => fn ($q) => $q->whereIn('status', [CampaignRecipient::PENDING, CampaignRecipient::QUEUED]),
            'recipients as opened_count' => fn ($q) => $q->whereNotNull('opened_at'),
            'recipients as clicked_count' => fn ($q) => $q->whereNotNull('clicked_at'),
        ]);
    }
}
