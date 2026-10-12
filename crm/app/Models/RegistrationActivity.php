<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'registration_participant_id', 'evr_assignment_id', 'evr_activity_id', 'name', 'type', 'event_date', 'start_time',
    'status', 'price', 'discount', 'total',
])]
class RegistrationActivity extends Model
{
    /** Assignment statuses that still hold a seat. */
    public const ACTIVE_STATUSES = ['reserved', 'confirmed'];

    protected function casts(): array
    {
        return ['event_date' => 'date:Y-m-d', 'price' => 'decimal:2', 'discount' => 'decimal:2', 'total' => 'decimal:2'];
    }

    public function participant(): BelongsTo
    {
        return $this->belongsTo(RegistrationParticipant::class, 'registration_participant_id');
    }
}
