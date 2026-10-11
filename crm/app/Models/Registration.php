<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A WooCommerce order with EVR participants, mirrored from the plugin.
 */
#[Fillable(['wc_order_id', 'number', 'status', 'currency', 'total', 'ordered_at', 'paid_at', 'billing', 'buyer_contact_id', 'edition_year'])]
class Registration extends Model
{
    /** WooCommerce statuses that mean the order was paid (or approved by the registration desk). */
    public const PAID_STATUSES = ['processing', 'completed'];

    public const STATUS_LABELS = [
        'pending' => 'Pendiente de pago',
        'processing' => 'Pagada',
        'completed' => 'Completada',
        'on-hold' => 'En espera',
        'cancelled' => 'Cancelada',
        'refunded' => 'Reembolsada',
        'failed' => 'Fallida',
        'checkout-draft' => 'Borrador',
    ];

    protected function casts(): array
    {
        return [
            'total' => 'decimal:2',
            'ordered_at' => 'datetime',
            'paid_at' => 'datetime',
            'billing' => 'array',
        ];
    }

    public function participants(): HasMany
    {
        return $this->hasMany(RegistrationParticipant::class);
    }

    public function buyer(): BelongsTo
    {
        return $this->belongsTo(Contact::class, 'buyer_contact_id');
    }

    public function isPaid(): bool
    {
        return in_array($this->status, self::PAID_STATUSES, true);
    }
}
