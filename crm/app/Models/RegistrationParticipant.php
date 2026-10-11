<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable([
    'registration_id', 'contact_id', 'evr_order_participant_id', 'evr_participant_id', 'full_name', 'email',
    'document_type', 'document_number', 'whatsapp', 'shirt_size', 'meal_type', 'allergies', 'entity', 'registered_by',
])]
class RegistrationParticipant extends Model
{
    public function registration(): BelongsTo
    {
        return $this->belongsTo(Registration::class);
    }

    public function contact(): BelongsTo
    {
        return $this->belongsTo(Contact::class);
    }

    public function activities(): HasMany
    {
        return $this->hasMany(RegistrationActivity::class)->orderBy('event_date')->orderBy('start_time');
    }
}
