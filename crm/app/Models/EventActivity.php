<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

/**
 * Mirror of an activity in the EVR plugin. The plugin owns capacity; these numbers are read-only copies.
 */
#[Fillable([
    'evr_id', 'type', 'name', 'destination', 'event_date', 'start_time', 'end_time', 'price',
    'capacity_total', 'reserved_count', 'confirmed_count', 'status',
])]
class EventActivity extends Model
{
    public const TYPE_LABELS = [
        'conference' => 'Conferencias',
        'birding' => 'Salidas',
        'workshop' => 'Talleres',
        'other' => 'Otras actividades',
    ];

    protected function casts(): array
    {
        return ['event_date' => 'date:Y-m-d', 'price' => 'decimal:2'];
    }
}
