<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['contact_id', 'year', 'role', 'details', 'contact_import_id'])]
class ContactEdition extends Model
{
    public const ROLE_ATTENDEE = 'asistente';

    public const ROLE_INTERESTED = 'interesado';

    protected function casts(): array
    {
        return ['details' => 'array'];
    }

    public function contact(): BelongsTo
    {
        return $this->belongsTo(Contact::class);
    }
}
