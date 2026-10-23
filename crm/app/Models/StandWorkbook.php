<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\Storage;

/**
 * An Excel book uploaded to the Stand comercial zone. The file keeps the formulas;
 * `sheets` holds what the screen shows (values, formulas, formats, merges).
 */
#[Fillable(['name', 'source', 'google_spreadsheet_id', 'google_sheet', 'original_name', 'path', 'sheets', 'edits', 'synced_at', 'sync_error', 'created_by', 'updated_by'])]
class StandWorkbook extends Model
{
    public const DIRECTORY = 'stands';

    public const SOURCE_EXCEL = 'excel';

    public const SOURCE_GOOGLE = 'google';

    protected function casts(): array
    {
        return ['sheets' => 'array', 'synced_at' => 'datetime'];
    }

    protected static function booted(): void
    {
        static::deleted(function (StandWorkbook $workbook) {
            if ($workbook->path) {
                Storage::disk('local')->delete($workbook->path);
            }
        });
    }

    public function absolutePath(): string
    {
        return Storage::disk('local')->path($this->path);
    }

    public function isGoogle(): bool
    {
        return $this->source === self::SOURCE_GOOGLE;
    }

    public function googleUrl(): ?string
    {
        return $this->google_spreadsheet_id ? 'https://docs.google.com/spreadsheets/d/'.$this->google_spreadsheet_id.'/edit' : null;
    }

    public function purchases(): HasMany
    {
        return $this->hasMany(StandPurchase::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function editor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }
}
