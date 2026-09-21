<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\ChartOfAccount;
use App\Models\FiscalPeriod;
use App\Models\FiscalYear;
use App\Models\GlTransaction;
use App\Models\PayrollEmployee;
use App\Models\PayrollRun;
use App\Models\PayrollSetting;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * W1 — Payroll run: compute, approve, post a balanced journal.
 */
final class PayrollRunTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RbacSeeder::class);

        $u = User::factory()->create();
        // Expense + liability accounts for the payroll map.
        $mk = fn (string $code, string $name, string $type, string $nb): int => ChartOfAccount::query()->create(['code' => $code, 'name' => $name, 'account_type' => $type, 'normal_balance' => $nb, 'level' => 1, 'is_postable' => true, 'status' => 'active'])->id;
        PayrollSetting::current()->update([
            'basic_salary_account_id' => $mk('5101', 'Basic salary', 'expense', 'debit'),
            'housing_account_id' => $mk('5102', 'Housing', 'expense', 'debit'),
            'transport_account_id' => $mk('5103', 'Transport', 'expense', 'debit'),
            'other_earnings_account_id' => $mk('5104', 'Other earnings', 'expense', 'debit'),
            'gosi_expense_account_id' => $mk('5105', 'GOSI expense', 'expense', 'debit'),
            'eosb_expense_account_id' => $mk('5106', 'EOSB expense', 'expense', 'debit'),
            'gosi_payable_account_id' => $mk('2301', 'GOSI payable', 'liability', 'credit'),
            'eosb_provision_account_id' => $mk('2302', 'EOSB provision', 'liability', 'credit'),
            'net_payable_account_id' => $mk('2303', 'Net salaries payable', 'liability', 'credit'),
        ]);

        // Saudi employee: basic 10,000, housing 2,000 → GOSI base 12,000.
        PayrollEmployee::query()->create(['employee_code' => 'E1', 'name' => 'Ahmed', 'is_saudi' => true, 'basic_salary' => 10000, 'housing_allowance' => 2000, 'transport_allowance' => 500, 'other_allowance' => 0, 'join_date' => '2024-01-01', 'status' => 'active', 'created_by' => $u->id]);
        // Expat employee: basic 8,000, housing 1,000.
        PayrollEmployee::query()->create(['employee_code' => 'E2', 'name' => 'John', 'is_saudi' => false, 'basic_salary' => 8000, 'housing_allowance' => 1000, 'transport_allowance' => 0, 'other_allowance' => 0, 'join_date' => '2024-01-01', 'status' => 'active', 'created_by' => $u->id]);

        $year = FiscalYear::query()->create(['code' => 'FY2026', 'name' => 'FY2026', 'start_date' => '2026-01-01', 'end_date' => '2026-12-31', 'status' => 'open']);
        FiscalPeriod::query()->create(['fiscal_year_id' => $year->id, 'period_number' => 1, 'name' => 'Jan 2026', 'start_date' => '2026-01-01', 'end_date' => '2026-01-31', 'status' => 'open']);
    }

    private function actAs(string $role): void
    {
        $u = User::factory()->create();
        $u->roles()->attach(Role::query()->where('name', $role)->firstOrFail());
        Sanctum::actingAs($u->fresh());
    }

    public function test_run_computes_gosi_and_net_per_employee(): void
    {
        $this->actAs('finance-manager');

        $run = $this->postJson('/api/v1/payroll/runs', ['period_year' => 2026, 'period_month' => 1, 'pay_date' => '2026-01-28'])
            ->assertCreated()->json('data');

        $this->assertCount(2, $run['lines']);
        // Saudi GOSI employee = 12,000 × 9.75% = 1,170; gross 12,500; net 11,330.
        $ahmed = collect($run['lines'])->firstWhere('employee_name', 'Ahmed');
        $this->assertEqualsWithDelta(1170, (float) $ahmed['gosi_employee'], 0.01);
        $this->assertEqualsWithDelta(12500, (float) $ahmed['gross'], 0.01);
        $this->assertEqualsWithDelta(11330, (float) $ahmed['net_pay'], 0.01);

        // Expat: no employee GOSI → net = gross 9,000.
        $john = collect($run['lines'])->firstWhere('employee_name', 'John');
        $this->assertEqualsWithDelta(0, (float) $john['gosi_employee'], 0.01);
        $this->assertEqualsWithDelta(9000, (float) $john['net_pay'], 0.01);
    }

    public function test_posting_writes_a_balanced_payroll_journal(): void
    {
        $this->actAs('finance-manager');

        $id = $this->postJson('/api/v1/payroll/runs', ['period_year' => 2026, 'period_month' => 1, 'pay_date' => '2026-01-28'])->assertCreated()->json('data.id');
        $this->postJson("/api/v1/payroll/runs/{$id}/approve")->assertOk()->assertJsonPath('data.status', 'approved');
        $this->postJson("/api/v1/payroll/runs/{$id}/post")->assertOk()->assertJsonPath('data.status', 'posted');

        $batch = PayrollRun::query()->find($id)->batch_number;
        $rows = GlTransaction::query()->where('batch_number', $batch)->get();

        $this->assertEqualsWithDelta($rows->sum('base_debit'), $rows->sum('base_credit'), 0.01);
        // Net payable credit = Ahmed 11,330 + John 9,000 = 20,330.
        $netAcc = PayrollSetting::current()->net_payable_account_id;
        $this->assertEqualsWithDelta(20330, $rows->firstWhere('account_id', $netAcc)->base_credit, 0.01);
    }

    public function test_duplicate_period_run_is_rejected(): void
    {
        $this->actAs('finance-manager');

        $this->postJson('/api/v1/payroll/runs', ['period_year' => 2026, 'period_month' => 1, 'pay_date' => '2026-01-28'])->assertCreated();
        $this->postJson('/api/v1/payroll/runs', ['period_year' => 2026, 'period_month' => 1, 'pay_date' => '2026-01-28'])->assertStatus(422);
    }
}
