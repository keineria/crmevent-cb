<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

#[Fillable([
    'registration_id', 'contact_id', 'evr_order_participant_id', 'evr_participant_id', 'full_name', 'email',
    'document_type', 'document_number', 'whatsapp', 'shirt_size', 'meal_type', 'allergies', 'entity', 'registered_by',
    'checkin_code', 'qr_queued_at', 'qr_sent_at',
])]
class RegistrationParticipant extends Model
{
    /** Letters and digits that can't be confused when read aloud or typed (no 0/O, 1/I/L). */
    private const CODE_ALPHABET = 'ABCDEFGHJKMNPQRSTUVWXYZ23456789';

    protected static function booted(): void
    {
        static::creating(function (self $participant) {
            $participant->checkin_code ??= self::newCheckinCode();
        });
    }

    public static function newCheckinCode(): string
    {
        do {
            $code = 'CBF-';
            for ($i = 0; $i < 8; $i++) {
                $code .= self::CODE_ALPHABET[random_int(0, strlen(self::CODE_ALPHABET) - 1)];
            }
        } while (static::where('checkin_code', $code)->exists());

        return $code;
    }

    /**
     * Pulls a check-in code out of whatever a scanner read: the bare code, or a link that ends with it.
     */
    public static function codeFrom(string $scanned): ?string
    {
        return preg_match('/CBF-['.self::CODE_ALPHABET.']{8}(?![A-Z0-9])/', Str::upper($scanned), $m) ? $m[0] : null;
    }

    protected function casts(): array
    {
        return ['qr_queued_at' => 'datetime', 'qr_sent_at' => 'datetime'];
    }

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

    public function checkIns(): HasMany
    {
        return $this->hasMany(CheckIn::class);
    }

    /**
     * Where the QR goes: the participant's own email, or the buyer's when the participant has none.
     */
    public function qrEmail(): ?string
    {
        return $this->email ?: ($this->registration->billing['email'] ?? null) ?: null;
    }
}
