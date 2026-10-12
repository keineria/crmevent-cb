<?php

namespace Tests\Feature\Finance;

use App\Models\AuditLog;
use App\Models\EventActivity;
use App\Models\Expense;
use App\Models\Registration;
use App\Models\User;
use App\Services\Finance\FinanceReport;
use App\Support\Roles;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class FinanceTest extends TestCase
{
    use RefreshDatabase;

    private int $seq = 0;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RoleSeeder::class);

        EventActivity::create(['evr_id' => 1, 'type' => 'conference', 'name' => 'Conferencias', 'event_date' => '2027-09-30', 'price' => 105000, 'capacity_total' => 500, 'status' => 'active']);
        EventActivity::create(['evr_id' => 2, 'type' => 'birding', 'name' => 'Salida Anchicayá', 'event_date' => '2027-10-01', 'start_time' => '05:00:00', 'price' => 250000, 'capacity_total' => 19, 'status' => 'active']);
        EventActivity::create(['evr_id' => 3, 'type' => 'workshop', 'name' => 'Taller de dibujo', 'event_date' => '2027-10-02', 'price' => 0, 'capacity_total' => 20, 'status' => 'active']);
    }

    private function user(string $role = Roles::ADMIN): User
    {
        return tap(User::factory()->create())->assignRole($role);
    }

    /**
     * A paid order with one participant and the given seats: [activity id, price, total, status].
     *
     * @param  list<array{0: int, 1: float, 2: float, 3?: string}>  $seats
     */
    private function order(array $seats, string $paidAt = '2026-09-10 15:00:00', string $status = 'processing', ?float $total = null, int $year = 2027): Registration
    {
        $n = ++$this->seq;
        $registration = Registration::create([
            'wc_order_id' => 1000 + $n, 'number' => (string) (1000 + $n), 'status' => $status, 'currency' => 'COP',
            'total' => $total ?? array_sum(array_column($seats, 2)), 'ordered_at' => $paidAt, 'paid_at' => $paidAt, 'edition_year' => $year,
        ]);
        $participant = $registration->participants()->create([
            'evr_order_participant_id' => $n, 'evr_participant_id' => $n, 'full_name' => "Persona {$n}",
        ]);
        foreach ($seats as $i => $seat) {
            $activity = EventActivity::where('evr_id', $seat[0])->first();
            $participant->activities()->create([
                'evr_assignment_id' => $n * 100 + $i, 'evr_activity_id' => $seat[0], 'name' => $activity->name, 'type' => $activity->type,
                'event_date' => $activity->event_date, 'status' => $seat[3] ?? 'confirmed', 'price' => $seat[1], 'discount' => $seat[1] - $seat[2], 'total' => $seat[2],
            ]);
        }

        return $registration;
    }

    private function expense(array $attributes): Expense
    {
        return Expense::create([
            'edition_year' => 2027, 'category' => 'transporte', 'concept' => 'Bus', 'mode' => Expense::MODE_FIXED,
            'unit_amount' => 0, 'quantity' => 1, 'spent_on' => '2026-09-20', ...$attributes,
        ]);
    }

    public function test_report_adds_sales_courtesies_and_costs_per_activity(): void
    {
        $this->order([[1, 105000, 105000], [2, 250000, 250000], [3, 0, 0]]); // free workshop is not a courtesy
        $this->order([[2, 250000, 200000]]); // discount code
        $this->order([[1, 105000, 0], [2, 250000, 0]]); // courtesy
        $this->order([[2, 250000, 250000]], status: 'pending'); // not paid, ignored
        $this->order([[2, 250000, 250000, 'removed']], total: 250000); // changed away from the outing, money kept
        $this->order([[1, 105000, 105000]], year: 2026); // other edition

        $this->expense(['evr_activity_id' => 2, 'concept' => 'Bus 19 pasajeros', 'unit_amount' => 1060000]);
        $this->expense(['evr_activity_id' => 2, 'concept' => 'Guías', 'category' => 'guias', 'unit_amount' => 100000, 'quantity' => 2]);
        $this->expense(['evr_activity_id' => 2, 'concept' => 'Almuerzo', 'category' => 'alimentacion', 'mode' => Expense::MODE_PER_PERSON, 'unit_amount' => 25000]);
        $this->expense(['concept' => 'Camisetas', 'category' => 'kits', 'mode' => Expense::MODE_PER_PERSON, 'unit_amount' => 20000]);
        $this->expense(['concept' => 'Auditorio', 'category' => 'espacio', 'unit_amount' => 3000000, 'paid' => true]);

        $summary = (new FinanceReport(2027))->summary();
        $kpis = $summary['kpis'];

        $this->assertSame(4, $kpis['orders']);
        $this->assertEqualsWithDelta(105000 + 250000 + 200000 + 0 + 250000, $kpis['income'], 0.01);
        $this->assertEqualsWithDelta(555000, $kpis['activity_sales'], 0.01);
        $this->assertEqualsWithDelta(250000, $kpis['adjustments'], 0.01);
        $this->assertSame(2, $kpis['courtesies']);
        $this->assertEqualsWithDelta(355000, $kpis['courtesy_value'], 0.01);
        $this->assertEqualsWithDelta(50000, $kpis['discounts'], 0.01);

        $outing = collect($summary['activities'])->firstWhere('id', 2);
        $this->assertSame(3, $outing['participants']);
        $this->assertSame(2, $outing['paying']);
        $this->assertSame(1, $outing['courtesies']);
        $this->assertEqualsWithDelta(450000, $outing['sales'], 0.01);
        $this->assertEqualsWithDelta(1260000, $outing['fixed_costs'], 0.01);
        $this->assertEqualsWithDelta(75000, $outing['variable_costs'], 0.01); // 3 seats eat lunch, courtesy included
        $this->assertEqualsWithDelta(450000 - 1335000, $outing['profit'], 0.01);
        // Average ticket 225,000 minus 25,000 lunch leaves 200,000 per person against 1,260,000 fixed.
        $this->assertSame(7, $outing['break_even']);

        $workshop = collect($summary['activities'])->firstWhere('id', 3);
        $this->assertSame(1, $workshop['participants']);
        $this->assertSame(0, $workshop['courtesies']);

        // 4 paid participants × 20,000 shirts + 3,000,000 hall.
        $this->assertEqualsWithDelta(3080000, $kpis['other_costs'], 0.01);
        $this->assertEqualsWithDelta(1335000 + 3080000, $kpis['costs'], 0.01);
        $this->assertEqualsWithDelta(1335000 + 80000, $kpis['unpaid_costs'], 0.01);
        $this->assertEqualsWithDelta($kpis['income'] - $kpis['costs'], $kpis['profit'], 0.01);
        $this->assertSame('Alimentación', collect($summary['by_category'])->firstWhere('category', 'alimentacion')['label']);
        $this->assertEqualsWithDelta(450000, collect($summary['by_type'])->firstWhere('type', 'birding')['sales'], 0.01);
    }

    public function test_date_range_uses_bogota_days(): void
    {
        // 03:00 UTC on the 11th is still the 10th in Bogotá.
        $this->order([[1, 105000, 105000]], paidAt: '2026-09-11 03:00:00');
        $this->order([[1, 105000, 105000]], paidAt: '2026-09-12 15:00:00');
        $this->expense(['unit_amount' => 500000, 'spent_on' => '2026-09-10']);
        $this->expense(['unit_amount' => 900000, 'spent_on' => '2026-08-01']);

        $day = (new FinanceReport(2027, '2026-09-10', '2026-09-10'))->summary();
        $this->assertSame(1, $day['kpis']['orders']);
        $this->assertEqualsWithDelta(500000, $day['kpis']['costs'], 0.01);
        $this->assertSame([['date' => '2026-09-10', 'income' => 105000.0, 'costs' => 500000.0]], $day['series']);

        $all = (new FinanceReport(2027))->summary();
        $this->assertSame(2, $all['kpis']['orders']);
        $this->assertSame('2026-08-01', $all['series'][0]['date']);
        $this->assertSame('2026-09-12', end($all['series'])['date']);
    }

    public function test_panel_renders_for_analysts_and_hides_editing(): void
    {
        $this->order([[1, 105000, 105000]]);

        $this->actingAs($this->user(Roles::ANALYST))->get('/finance')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->component('finance/index')
                ->where('filters.edicion', 2027)
                ->where('kpis.orders', 1)
                ->where('canEdit', false)
                ->has('activities', 3));

        $this->actingAs($this->user(Roles::ANALYST))->get('/finance/expenses')->assertOk();
    }

    public function test_other_roles_cannot_see_finance(): void
    {
        foreach ([Roles::MARKETING, Roles::REGISTRATION, Roles::REGISTRATION_ADMIN] as $role) {
            $this->actingAs($this->user($role))->get('/finance')->assertForbidden();
        }
        $this->actingAs($this->user(Roles::ANALYST))
            ->post('/finance/expenses', ['edition_year' => 2027, 'category' => 'otros', 'concept' => 'x', 'mode' => 'fixed', 'unit_amount' => 1, 'spent_on' => '2026-09-01'])
            ->assertForbidden();
    }

    public function test_admin_records_edits_and_deletes_costs(): void
    {
        $admin = $this->user();

        $this->actingAs($admin)->post('/finance/expenses', [
            'edition_year' => 2027, 'evr_activity_id' => 2, 'category' => 'alimentacion', 'concept' => 'Almuerzo',
            'mode' => 'per_person', 'unit_amount' => 25000, 'quantity' => 5, 'spent_on' => '2026-09-20', 'paid' => false,
        ])->assertRedirect()->assertSessionHas('success');

        $expense = Expense::sole();
        $this->assertSame('1.00', $expense->quantity, 'per-person costs ignore the quantity');
        $this->assertSame($admin->id, $expense->created_by);

        $this->actingAs($admin)->put("/finance/expenses/{$expense->id}", [
            'edition_year' => 2027, 'evr_activity_id' => null, 'category' => 'kits', 'concept' => 'Camisetas',
            'mode' => 'fixed', 'unit_amount' => 18000, 'quantity' => 300, 'spent_on' => '2026-09-21', 'paid' => true,
        ])->assertRedirect();
        $expense->refresh();
        $this->assertNull($expense->evr_activity_id);
        $this->assertEqualsWithDelta(5400000, $expense->fixedAmount(), 0.01);
        $this->assertTrue($expense->paid);

        $this->actingAs($admin)->get('/finance/expenses?edicion=2027&actividad=otros')
            ->assertInertia(fn (Assert $page) => $page->component('finance/expenses')
                ->has('expenses', 1)
                ->where('totals.paid', 5400000));

        $this->actingAs($admin)->delete("/finance/expenses/{$expense->id}")->assertRedirect();
        $this->assertSame(0, Expense::count());
        $this->assertSame(['expense.created', 'expense.updated', 'expense.deleted'], AuditLog::orderBy('id')->pluck('action')->all());
    }

    public function test_expense_validation(): void
    {
        $this->actingAs($this->user())->post('/finance/expenses', [
            'edition_year' => 2027, 'evr_activity_id' => 99, 'category' => 'nope', 'concept' => '', 'mode' => 'fixed', 'unit_amount' => -1, 'spent_on' => '20-09-2026',
        ])->assertSessionHasErrors(['evr_activity_id', 'category', 'concept', 'unit_amount', 'spent_on']);
    }
}
