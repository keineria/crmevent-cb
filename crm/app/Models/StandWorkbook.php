<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Storage;

/**
 * An Excel book uploaded to the Stand comercial zone. The file keeps the formulas;
 * `sheets` holds what the screen shows (values, formulas, formats, merges).
 */
#[Fillable(['name', 'original_name', 'path', 'sheets', 'edits', 'created_by', 'updated_by'])]
class StandWorkbook extends Model
{
    public const DIRECTORY = 'stands';

    protected function casts(): array
    {
        return ['sheets' => 'array'];
    }

    protected static function booted(): void
    {
        static::deleted(function (StandWorkbook $workbook) {
            Storage::disk('local')->delete($workbook->path);
        });
    }

    public function absolutePath(): string
    {
        return Storage::disk('local')->path($this->path);
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
