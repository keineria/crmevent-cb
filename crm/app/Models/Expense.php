<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A cost of the fair. Linked to a plugin activity it is that activity's cost; otherwise it is an "other expense".
 * A per-person cost is multiplied by the paid participants it applies to when the report runs.
 */
#[Fillable([
    'edition_year', 'evr_activity_id', 'category', 'concept', 'mode', 'unit_amount', 'quantity',
    'supplier', 'spent_on', 'paid', 'notes', 'created_by', 'updated_by',
])]
class Expense extends Model
{
    public const MODE_FIXED = 'fixed';

    public const MODE_PER_PERSON = 'per_person';

    public const MODES = [
        self::MODE_FIXED => 'Valor fijo',
        self::MODE_PER_PERSON => 'Por inscrito',
    ];

    public const CATEGORIES = [
        'transporte' => 'Transporte',
        'guias' => 'Guías y asistentes',
        'alimentacion' => 'Alimentación',
        'entradas' => 'Entradas y reservas',
        'logistica' => 'Logística y montaje',
        'espacio' => 'Alquiler de espacios',
        'personal' => 'Personal',
        'conferencistas' => 'Conferencistas',
        'kits' => 'Kits y camisetas',
        'publicidad' => 'Publicidad',
        'comisiones' => 'Comisiones de pago',
        'otros' => 'Otros',
    ];

    protected function casts(): array
    {
        return [
            'spent_on' => 'date:Y-m-d',
            'unit_amount' => 'decimal:2',
            'quantity' => 'decimal:2',
            'paid' => 'boolean',
        ];
    }

    public function activity(): BelongsTo
    {
        return $this->belongsTo(EventActivity::class, 'evr_activity_id', 'evr_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function isPerPerson(): bool
    {
        return $this->mode === self::MODE_PER_PERSON;
    }

    /** Amount of a fixed cost; per-person costs depend on the participants and are worked out by the report. */
    public function fixedAmount(): float
    {
        return round((float) $this->unit_amount * (float) $this->quantity, 2);
    }
}
