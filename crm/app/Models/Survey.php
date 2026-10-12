<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * The survey of one plugin activity, with the questions it was sent with.
 */
#[Fillable([
    'evr_activity_id', 'activity_type', 'activity_name', 'activity_date', 'title', 'intro', 'questions', 'require_check_in',
    'source', 'created_by',
])]
class Survey extends Model
{
    public const SOURCE_AUTO = 'auto';

    public const SOURCE_MANUAL = 'manual';

    /** For this long after it is created, people checked in late still get it. */
    public const COLLECT_HOURS = 48;

    protected function casts(): array
    {
        return ['activity_date' => 'date:Y-m-d', 'questions' => 'array', 'require_check_in' => 'boolean'];
    }

    public function invitations(): HasMany
    {
        return $this->hasMany(SurveyInvitation::class);
    }

    public function activity(): BelongsTo
    {
        return $this->belongsTo(EventActivity::class, 'evr_activity_id', 'evr_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
