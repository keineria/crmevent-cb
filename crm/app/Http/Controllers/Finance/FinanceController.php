<?php

namespace App\Http\Controllers\Finance;

use App\Http\Controllers\Controller;
use App\Models\EventActivity;
use App\Models\Expense;
use App\Models\PluginConnection;
use App\Services\Finance\FinanceReport;
use App\Support\Roles;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class FinanceController extends Controller
{
    public function __invoke(Request $request): Response
    {
        $filters = self::filters($request);
        $report = new FinanceReport($filters['edicion'], $filters['desde'], $filters['hasta']);

        return Inertia::render('finance/index', [
            ...$report->summary(),
            'filters' => $filters,
            'editions' => self::editionOptions(),
            'types' => EventActivity::TYPE_LABELS,
            'categories' => Expense::CATEGORIES,
            'modes' => Expense::MODES,
            'canEdit' => $request->user()->hasAnyRole(Roles::FINANCE_EDITORS),
            'today' => now(FinanceReport::TIMEZONE)->toDateString(),
            'lastSync' => PluginConnection::query()->first()?->last_synced_at?->toIso8601String(),
        ]);
    }

    /**
     * Edition and date range shared by the finance pages.
     *
     * @return array{edicion: int, desde: ?string, hasta: ?string}
     */
    public static function filters(Request $request): array
    {
        $data = $request->validate([
            'edicion' => ['nullable', 'integer', 'between:2000,2100'],
            'desde' => ['nullable', 'date_format:Y-m-d'],
            'hasta' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:desde'],
        ]);

        return [
            'edicion' => (int) ($data['edicion'] ?? FinanceReport::defaultEdition()),
            'desde' => $data['desde'] ?? null,
            'hasta' => $data['hasta'] ?? null,
        ];
    }

    /** @return list<int> */
    public static function editionOptions(): array
    {
        $current = (int) now(FinanceReport::TIMEZONE)->year;

        return collect(FinanceReport::editions())->push($current, $current + 1)->unique()->sortDesc()->values()->all();
    }
}
