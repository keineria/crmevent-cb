<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * Reusable starting point for a campaign. A campaign copies its content, so editing a template never changes what was sent.
 *
 * Rows with a system_key are the emails the CRM sends on its own; they are edited from their own page and never listed,
 * copied or deleted as campaign templates.
 */
#[Fillable(['name', 'subject', 'preheader', 'body', 'body_format', 'updated_by'])]
class MailTemplate extends Model
{
    public const CHECKIN_QR = 'checkin_qr';

    public const CERTIFICATE = 'certificate';

    public const SURVEY = 'survey';

    public static function system(string $key): self
    {
        return static::where('system_key', $key)->firstOrFail();
    }

    /**
     * @param  Builder<self>  $query
     */
    public function scopeForCampaigns(Builder $query): void
    {
        $query->whereNull('system_key');
    }
}
