<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Model;

/**
 * Connection to the "EVR CRM Bridge" WordPress add-on. A single row.
 */
#[Fillable(['base_url', 'api_key', 'created_after', 'enabled', 'updated_by'])]
#[Hidden(['api_key'])]
class PluginConnection extends Model
{
    /** Keiner's decision (2026-10-11): only purchases from August 2026 onward come from the plugin. */
    public const DEFAULT_CREATED_AFTER = '2026-08-01';

    protected function casts(): array
    {
        return [
            'api_key' => 'encrypted',
            'created_after' => 'date',
            'enabled' => 'boolean',
            'last_synced_at' => 'datetime',
            'last_result' => 'array',
            'last_reconciled_at' => 'datetime',
            'last_reconcile_result' => 'array',
        ];
    }

    public static function current(): self
    {
        return static::firstOrNew([], ['created_after' => self::DEFAULT_CREATED_AFTER]);
    }

    public function isConfigured(): bool
    {
        return filled($this->base_url) && filled($this->api_key);
    }

    public function resetCursor(): void
    {
        $this->forceFill(['cursor_updated' => null, 'cursor_order' => 0]);
    }
}
