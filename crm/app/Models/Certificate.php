<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * The attendance certificate of one plugin activity, drawn with its category's design.
 * Nothing goes out until someone presses Enviar on it.
 */
#[Fillable([
    'certificate_category_id', 'evr_activity_id', 'activity_name', 'activity_date', 'hours', 'require_check_in',
    'sent_at', 'sent_by', 'created_by',
])]
class Certificate extends Model
{
    protected function casts(): array
    {
        return [
            'activity_date' => 'date:Y-m-d',
            'hours' => 'decimal:1',
            'require_check_in' => 'boolean',
            'sent_at' => 'datetime',
        ];
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(CertificateCategory::class, 'certificate_category_id');
    }

    public function recipients(): HasMany
    {
        return $this->hasMany(CertificateRecipient::class);
    }

    public function activity(): BelongsTo
    {
        return $this->belongsTo(EventActivity::class, 'evr_activity_id', 'evr_id');
    }

    public function sender(): BelongsTo
    {
        return $this->belongsTo(User::class, 'sent_by');
    }
}
