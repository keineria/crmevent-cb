<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Follow-up of one sold stand (paid, documents complete, invoiced), keyed by its zone and number in the sheet.
 */
#[Fillable(['stand_workbook_id', 'stand_key', 'name', 'paid', 'documents', 'invoiced', 'updated_by'])]
class StandPurchase extends Model
{
    public const FLAGS = [
        'paid' => 'Pagó',
        'documents' => 'Documentos completos',
        'invoiced' => 'Facturado',
    ];

    protected function casts(): array
    {
        return ['paid' => 'boolean', 'documents' => 'boolean', 'invoiced' => 'boolean'];
    }

    public function workbook(): BelongsTo
    {
        return $this->belongsTo(StandWorkbook::class, 'stand_workbook_id');
    }

    public function editor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }
}
