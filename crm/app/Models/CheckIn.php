<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A participant arriving to one of their activities. Lives only in the CRM; the plugin knows nothing about it.
 */
#[Fillable(['registration_activity_id', 'registration_participant_id', 'evr_activity_id', 'method', 'user_id', 'checked_in_at'])]
class CheckIn extends Model
{
    /** The fair runs in Colombia: "today" at the desk is Bogotá's today. */
    public const TIMEZONE = 'America/Bogota';

    public const METHOD_QR = 'qr';

    public const METHOD_DOCUMENT = 'documento';

    public const METHOD_SEARCH = 'busqueda';

    public const METHOD_LIST = 'lista';

    public const METHODS = [self::METHOD_QR, self::METHOD_DOCUMENT, self::METHOD_SEARCH, self::METHOD_LIST];

    /** Activity types checked in at the venue entrance. */
    public const ENTRANCE_TYPES = ['conference', 'workshop', 'other'];

    /** Activity types checked in at the outing's meeting point. */
    public const TRIP_TYPES = ['birding'];

    protected function casts(): array
    {
        return ['checked_in_at' => 'datetime'];
    }

    public function activity(): BelongsTo
    {
        return $this->belongsTo(RegistrationActivity::class, 'registration_activity_id');
    }

    public function participant(): BelongsTo
    {
        return $this->belongsTo(RegistrationParticipant::class, 'registration_participant_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
