<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

#[Fillable(['campaign_id', 'contact_id', 'email', 'token', 'status', 'error', 'attempted_at', 'sent_at', 'opened_at', 'open_count', 'clicked_at', 'click_count'])]
class CampaignRecipient extends Model
{
    public const PENDING = 'pending';

    /** Handed to the queue; counts against the hourly limit. */
    public const QUEUED = 'queued';

    public const SENT = 'sent';

    public const FAILED = 'failed';

    /** Unsubscribed or lost consent between the snapshot and the send. */
    public const SKIPPED = 'skipped';

    public const STATUS_LABELS = [
        self::PENDING => 'En espera',
        self::QUEUED => 'En cola',
        self::SENT => 'Enviado',
        self::FAILED => 'Falló',
        self::SKIPPED => 'Omitido',
    ];

    protected static function booted(): void
    {
        static::creating(function (self $recipient) {
            $recipient->token ??= self::newToken();
        });
    }

    public static function newToken(): string
    {
        return Str::random(40);
    }

    protected function casts(): array
    {
        return [
            'attempted_at' => 'datetime',
            'sent_at' => 'datetime',
            'opened_at' => 'datetime',
            'clicked_at' => 'datetime',
        ];
    }

    public function campaign(): BelongsTo
    {
        return $this->belongsTo(Campaign::class);
    }

    public function contact(): BelongsTo
    {
        return $this->belongsTo(Contact::class);
    }

    public function clicks(): HasMany
    {
        return $this->hasMany(CampaignClick::class);
    }
}
