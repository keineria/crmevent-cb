<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'profile', 'provider', 'host', 'port', 'encryption', 'aws_region', 'username', 'password',
    'from_name', 'from_address', 'reply_to', 'dkim_selector', 'hourly_limit', 'daily_limit',
    'track_opens', 'track_clicks', 'footer_text', 'updated_by',
])]
#[Hidden(['password', 'active_config'])]
class MailSetting extends Model
{
    public const PROFILE_TRANSACTIONAL = 'transactional';

    public const PROFILE_BROADCAST = 'broadcast';

    public const PROFILES = [self::PROFILE_TRANSACTIONAL, self::PROFILE_BROADCAST];

    public const PROVIDERS = ['smtp', 'ses', 'brevo', 'resend'];

    /** Fields that change how mail is delivered; editing any of them requires a new test send. */
    public const TRANSPORT_FIELDS = ['provider', 'host', 'port', 'encryption', 'aws_region', 'username', 'password', 'from_name', 'from_address', 'reply_to'];

    protected function casts(): array
    {
        return [
            'password' => 'encrypted',
            'active_config' => 'encrypted:array',
            'port' => 'integer',
            'track_opens' => 'boolean',
            'track_clicks' => 'boolean',
            'verified_at' => 'datetime',
        ];
    }

    public static function forProfile(string $profile): self
    {
        return static::firstOrNew(['profile' => $profile], [
            'provider' => 'smtp',
            'encryption' => 'tls',
            'port' => 587,
            'track_opens' => true,
            'track_clicks' => true,
        ]);
    }

    /**
     * SMTP connection settings for Laravel's on-demand mailer, from the current (possibly unverified) values.
     *
     * @return array<string, mixed>
     */
    public function transportConfig(): array
    {
        return [
            'transport' => 'smtp',
            'scheme' => $this->encryption === 'ssl' ? 'smtps' : 'smtp',
            'host' => $this->host,
            'port' => $this->port,
            'username' => $this->username,
            'password' => $this->password,
            'timeout' => 15,
            'from' => ['address' => $this->from_address, 'name' => $this->from_name],
            'reply_to' => $this->reply_to,
        ];
    }

    public function isActive(): bool
    {
        return $this->active_config !== null;
    }

    public function maskedPassword(): ?string
    {
        if (! $this->password) {
            return null;
        }

        return str_repeat('•', 8).substr($this->password, -4);
    }

    public function updatedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }
}
