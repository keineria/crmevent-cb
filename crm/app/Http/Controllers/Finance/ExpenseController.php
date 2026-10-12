<?php

namespace App\Http\Controllers\Finance;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\EventActivity;
use App\Models\Expense;
use App\Services\Finance\FinanceReport;
use App\Support\Roles;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class ExpenseController extends Controller
{
    public function index(Request $request): Response
    {
        $filters = FinanceController::filters($request);
        $filters += $request->validate([
            'q' => ['nullable', 'string', 'max:120'],
            'categoria' => ['nullable', Rule::in(array_keys(Expense::CATEGORIES))],
            'actividad' => ['nullable', 'string', 'max:20'],
            'pago' => ['nullable', 'in:pagados,pendientes'],
        ]);

        // Per-person amounts depend on the paid seats, so they come from the report for the same edition and range.
        $lines = (new FinanceReport($filters['edicion'], $filters['desde'], $filters['hasta']))->costLines()->keyBy('id');

        $expenses = Expense::query()
            ->with(['activity:evr_id,name,type,event_date', 'creator:id,name'])
            ->where('edition_year', $filters['edicion'])
            ->when($filters['desde'] ?? null, fn (Builder $q, string $d) => $q->where(fn (Builder $w) => $w->where('mode', Expense::MODE_PER_PERSON)->orWhere('spent_on', '>=', $d)))
            ->when($filters['hasta'] ?? null, fn (Builder $q, string $d) => $q->where(fn (Builder $w) => $w->where('mode', Expense::MODE_PER_PERSON)->orWhere('spent_on', '<=', $d)))
            ->when($filters['q'] ?? null, fn (Builder $q, string $t) => $q->where(fn (Builder $w) => $w->where('concept', 'like', "%{$t}%")->orWhere('supplier', 'like', "%{$t}%")))
            ->when($filters['categoria'] ?? null, fn (Builder $q, string $c) => $q->where('category', $c))
            ->when($filters['actividad'] ?? null, fn (Builder $q, string $a) => $a === 'otros' ? $q->whereNull('evr_activity_id') : $q->where('evr_activity_id', (int) $a))
            ->when($filters['pago'] ?? null, fn (Builder $q, string $p) => $q->where('paid', $p === 'pagados'))
            ->orderByDesc('spent_on')->orderByDesc('id')
            ->get()
            ->map(fn (Expense $e) => [
                'id' => $e->id,
                'edition_year' => $e->edition_year,
                'activity_id' => $e->evr_activity_id,
                'activity' => $e->activity?->name,
                'category' => $e->category,
                'concept' => $e->concept,
                'mode' => $e->mode,
                'unit_amount' => (float) $e->unit_amount,
                'quantity' => (float) $e->quantity,
                'people' => $lines[$e->id]['people'] ?? null,
                'amount' => (float) ($lines[$e->id]['amount'] ?? 0),
                'supplier' => $e->supplier,
                'spent_on' => $e->spent_on->toDateString(),
                'paid' => $e->paid,
                'notes' => $e->notes,
                'creator' => $e->creator?->name,
            ]);

        return Inertia::render('finance/expenses', [
            'expenses' => $expenses,
            'totals' => [
                'amount' => round($expenses->sum('amount'), 2),
                'paid' => round($expenses->where('paid', true)->sum('amount'), 2),
                'pending' => round($expenses->where('paid', false)->sum('amount'), 2),
            ],
            'filters' => $filters,
            'editions' => FinanceController::editionOptions(),
            'activities' => self::activityOptions($filters['edicion']),
            'categories' => Expense::CATEGORIES,
            'modes' => Expense::MODES,
            'types' => EventActivity::TYPE_LABELS,
            'canEdit' => $request->user()->hasAnyRole(Roles::FINANCE_EDITORS),
            'today' => now(FinanceReport::TIMEZONE)->toDateString(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $expense = Expense::create([...$this->validated($request), 'created_by' => $request->user()->id, 'updated_by' => $request->user()->id]);
        AuditLog::record('expense.created', $expense, ['concepto' => $expense->concept, 'valor' => (float) $expense->unit_amount]);

        return back()->with('success', "Gasto «{$expense->concept}» guardado.");
    }

    public function update(Request $request, Expense $expense): RedirectResponse
    {
        $expense->fill([...$this->validated($request), 'updated_by' => $request->user()->id]);
        $changed = array_keys($expense->getDirty());
        $expense->save();
        AuditLog::record('expense.updated', $expense, ['campos' => $changed]);

        return back()->with('success', "Gasto «{$expense->concept}» actualizado.");
    }

    public function destroy(Expense $expense): RedirectResponse
    {
        AuditLog::record('expense.deleted', $expense, ['concepto' => $expense->concept, 'valor' => (float) $expense->unit_amount, 'modo' => $expense->mode]);
        $expense->delete();

        return back()->with('success', "Gasto «{$expense->concept}» eliminado.");
    }

    /** @return array<string, mixed> */
    private function validated(Request $request): array
    {
        $data = $request->validate([
            'edition_year' => ['required', 'integer', 'between:2000,2100'],
            'evr_activity_id' => ['nullable', 'integer', Rule::exists('event_activities', 'evr_id')],
            'category' => ['required', Rule::in(array_keys(Expense::CATEGORIES))],
            'concept' => ['required', 'string', 'max:191'],
            'mode' => ['required', Rule::in(array_keys(Expense::MODES))],
            'unit_amount' => ['required', 'numeric', 'min:0', 'max:999999999999'],
            'quantity' => ['nullable', 'numeric', 'min:0.01', 'max:99999999'],
            'supplier' => ['nullable', 'string', 'max:191'],
            'spent_on' => ['required', 'date_format:Y-m-d'],
            'paid' => ['boolean'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ], [], [
            'unit_amount' => 'valor', 'concept' => 'concepto', 'spent_on' => 'fecha', 'quantity' => 'cantidad', 'category' => 'categoría',
        ]);

        // The count of a per-person cost is the paid seats, worked out by the report.
        $data['quantity'] = $data['mode'] === Expense::MODE_PER_PERSON ? 1 : ($data['quantity'] ?? 1);
        $data['paid'] = (bool) ($data['paid'] ?? false);

        return $data;
    }

    /**
     * Activities a cost can be linked to: the edition's, then any other still open.
     *
     * @return list<array{id: int, name: string, type: string, date: ?string}>
     */
    public static function activityOptions(int $year): array
    {
        return EventActivity::query()
            ->where(fn (Builder $q) => $q->whereYear('event_date', $year)->orWhereNull('event_date'))
            ->where('status', '!=', 'archived')
            ->orderBy('event_date')->orderBy('start_time')->orderBy('name')
            ->get(['evr_id', 'name', 'type', 'event_date'])
            ->map(fn (EventActivity $a) => ['id' => $a->evr_id, 'name' => $a->name, 'type' => $a->type, 'date' => $a->event_date?->toDateString()])
            ->all();
    }
}
