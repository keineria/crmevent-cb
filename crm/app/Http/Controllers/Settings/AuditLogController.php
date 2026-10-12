<?php

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\CheckIn;
use App\Models\User;
use App\Support\AuditActions;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Everything staff did in the CRM, for admins: who, what, to whom, when and from where.
 */
class AuditLogController extends Controller
{
    private const PER_PAGE = 50;

    public function index(Request $request): Response
    {
        $filters = $this->filters($request);

        $page = $this->query($filters)->with('user:id,name')->paginate(self::PER_PAGE)->withQueryString();

        return Inertia::render('settings/audit', [
            'entries' => $page->through(fn (AuditLog $log) => $this->row($log)),
            'filters' => (object) array_filter($filters, fn ($v) => $v !== null && $v !== ''),
            'users' => User::orderBy('name')->get(['id', 'name', 'email', 'deactivated_at'])->map(fn (User $u) => [
                'id' => $u->id,
                'name' => $u->name,
                'active' => $u->isActive(),
            ]),
            'modules' => AuditActions::MODULES,
            'actions' => collect(AuditActions::ACTIONS)->map(fn ($a, $key) => ['value' => $key, 'module' => $a[0], 'label' => $a[1]])->values(),
        ]);
    }

    public function export(Request $request): StreamedResponse
    {
        $filters = $this->filters($request);
        $query = $this->query($filters)->with('user:id,name');
        $name = 'auditoria-'.now(CheckIn::TIMEZONE)->format('Y-m-d-His').'.csv';

        AuditLog::record('audit.exported', null, array_filter($filters, fn ($v) => $v !== null && $v !== ''));

        return response()->streamDownload(function () use ($query) {
            $out = fopen('php://output', 'w');
            // BOM so Excel opens accents correctly.
            fwrite($out, "\xEF\xBB\xBF");
            fputcsv($out, ['Fecha', 'Usuario', 'Módulo', 'Acción', 'Sobre', 'Detalle', 'IP', 'Navegador']);
            $query->chunk(500, function ($logs) use ($out) {
                foreach ($logs as $log) {
                    $row = $this->row($log);
                    fputcsv($out, [
                        $log->created_at->setTimezone(CheckIn::TIMEZONE)->format('Y-m-d H:i:s'),
                        $row['user'], $row['module'], $row['label'], $row['subject'],
                        $log->changes ? json_encode($log->changes, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : '',
                        $log->ip, $log->user_agent,
                    ]);
                }
            });
            fclose($out);
        }, $name, ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    /**
     * @return array{user: ?string, module: ?string, action: ?string, from: ?string, to: ?string, q: ?string}
     */
    private function filters(Request $request): array
    {
        $data = $request->validate([
            'user' => ['nullable', 'integer'],
            'module' => ['nullable', Rule::in(array_keys(AuditActions::MODULES))],
            'action' => ['nullable', 'string', 'max:60'],
            'from' => ['nullable', 'date_format:Y-m-d'],
            'to' => ['nullable', 'date_format:Y-m-d'],
            'q' => ['nullable', 'string', 'max:100'],
        ]);

        return [
            'user' => isset($data['user']) ? (string) $data['user'] : null,
            'module' => $data['module'] ?? null,
            'action' => $data['action'] ?? null,
            'from' => $data['from'] ?? null,
            'to' => $data['to'] ?? null,
            'q' => isset($data['q']) ? trim($data['q']) : null,
        ];
    }

    /**
     * @param  array<string, ?string>  $filters
     * @return Builder<AuditLog>
     */
    private function query(array $filters): Builder
    {
        $tz = CheckIn::TIMEZONE;

        return AuditLog::query()
            ->when($filters['user'], fn (Builder $q, $id) => $q->where('user_id', (int) $id))
            ->when($filters['module'], fn (Builder $q, $module) => $q->whereIn('action', AuditActions::inModule($module)))
            ->when($filters['action'], fn (Builder $q, $action) => $q->where('action', $action))
            ->when($filters['from'], fn (Builder $q, $from) => $q->where('created_at', '>=', Carbon::createFromFormat('Y-m-d', $from, $tz)->startOfDay()->utc()))
            ->when($filters['to'], fn (Builder $q, $to) => $q->where('created_at', '<=', Carbon::createFromFormat('Y-m-d', $to, $tz)->endOfDay()->utc()))
            ->when($filters['q'], function (Builder $q, $term) {
                $like = '%'.str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $term).'%';
                $q->where(fn (Builder $w) => $w->where('subject_label', 'like', $like)
                    ->orWhere('user_name', 'like', $like)
                    ->orWhere('ip', 'like', $like)
                    ->orWhere('changes', 'like', $like));
            })
            ->orderByDesc('created_at')
            ->orderByDesc('id');
    }

    /**
     * @return array<string, mixed>
     */
    private function row(AuditLog $log): array
    {
        $module = AuditActions::module($log->action);

        return [
            'id' => $log->id,
            'at' => $log->created_at->toIso8601String(),
            'user' => $log->user_name ?? $log->user?->name ?? 'Sistema',
            'user_id' => $log->user_id,
            'action' => $log->action,
            'label' => AuditActions::label($log->action),
            'module' => $module ? AuditActions::MODULES[$module] : 'Otros',
            'module_key' => $module,
            'subject' => $log->subject_label ?? ($log->subject_type ? class_basename($log->subject_type)." #{$log->subject_id}" : null),
            'changes' => $log->changes,
            'ip' => $log->ip,
            'user_agent' => $log->user_agent,
        ];
    }
}
