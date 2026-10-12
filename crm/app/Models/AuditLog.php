<?php

namespace App\Models;

use App\Support\AuditActions;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Support\Str;

/**
 * One thing a person did in the CRM. Admins read these in Configuración › Auditoría.
 */
#[Fillable(['user_id', 'user_name', 'action', 'subject_type', 'subject_id', 'subject_label', 'changes', 'ip', 'user_agent'])]
class AuditLog extends Model
{
    public const UPDATED_AT = null;

    protected function casts(): array
    {
        return ['changes' => 'array', 'created_at' => 'datetime'];
    }

    public static function record(string $action, ?Model $subject = null, array $changes = [], ?User $user = null): self
    {
        $user ??= auth()->user();
        $request = app()->runningInConsole() ? null : request();

        return static::create([
            'user_id' => $user?->getKey(),
            'user_name' => $user?->name,
            'action' => $action,
            'subject_type' => $subject?->getMorphClass(),
            'subject_id' => $subject?->getKey(),
            'subject_label' => $subject ? Str::limit(AuditActions::subjectLabel($subject), 188) : null,
            'changes' => $changes ?: null,
            'ip' => $request?->ip(),
            'user_agent' => $request ? Str::limit((string) $request->userAgent(), 250, '') ?: null : null,
        ]);
    }

    public function subject(): MorphTo
    {
        return $this->morphTo();
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
