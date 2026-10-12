<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'user_id', 'original_name', 'path', 'kind', 'edition_year', 'has_consent', 'headers', 'sample', 'mapping',
    'status', 'total_rows', 'created_count', 'updated_count', 'skipped_count', 'errors', 'finished_at',
])]
class ContactImport extends Model
{
    public const KIND_ATTENDEE = 'asistente';

    public const KIND_INTERESTED = 'interesado';

    public const KINDS = [self::KIND_ATTENDEE, self::KIND_INTERESTED];

    public const STATUS_MAPPING = 'mapping';

    public const STATUS_QUEUED = 'queued';

    public const STATUS_PROCESSING = 'processing';

    public const STATUS_DONE = 'done';

    public const STATUS_FAILED = 'failed';

    /** Row-level errors kept for the report; the rest are only counted. */
    public const MAX_ERRORS = 200;

    protected function casts(): array
    {
        return [
            'has_consent' => 'boolean',
            'headers' => 'array',
            'sample' => 'array',
            'mapping' => 'array',
            'errors' => 'array',
            'finished_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function isRunning(): bool
    {
        return in_array($this->status, [self::STATUS_QUEUED, self::STATUS_PROCESSING], true);
    }
}
