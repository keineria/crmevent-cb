<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

/**
 * One person's copy of a certificate. Name and document are kept as printed, so the verification page
 * keeps answering the same even if the plugin data changes later.
 */
#[Fillable([
    'certificate_id', 'registration_participant_id', 'person_key', 'full_name', 'document_number', 'email', 'code',
    'queued_at', 'sent_at', 'failed_at', 'error',
])]
class CertificateRecipient extends Model
{
    /** Same alphabet as the check-in code: nothing that reads ambiguously (no 0/O, 1/I/L). */
    private const CODE_ALPHABET = 'ABCDEFGHJKMNPQRSTUVWXYZ23456789';

    protected static function booted(): void
    {
        static::creating(function (self $recipient) {
            $recipient->code ??= self::newCode();
        });
    }

    public static function newCode(): string
    {
        do {
            $code = 'CERT-';
            for ($i = 0; $i < 10; $i++) {
                $code .= self::CODE_ALPHABET[random_int(0, strlen(self::CODE_ALPHABET) - 1)];
            }
        } while (static::where('code', $code)->exists());

        return $code;
    }

    public static function normalizeCode(string $code): string
    {
        return Str::upper(trim($code));
    }

    protected function casts(): array
    {
        return ['queued_at' => 'datetime', 'sent_at' => 'datetime', 'failed_at' => 'datetime'];
    }

    public function certificate(): BelongsTo
    {
        return $this->belongsTo(Certificate::class);
    }

    public function participant(): BelongsTo
    {
        return $this->belongsTo(RegistrationParticipant::class, 'registration_participant_id');
    }

    public function verificationUrl(): string
    {
        return route('certificates.verify', $this->code);
    }
}
