<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\ChartOfAccount;
use App\Models\EmployeeLoan;
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
 * W3 — Employee loans + payroll recovery.
 */
final class EmployeeLoanTest extends TestCase
{
    use RefreshDatabase;

    private int $loanRecvId;
    private int $empId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RbacSeeder::class);

        $u = User::factory()->create();
        $mk = fn (string $code, string $name, string $type, string $nb): int => ChartOfAccount::query()->create(['code' => $code, 'name' => $name, 'account_type' => $type, 'normal_balance' => $nb, 'level' => 1, 'is_postable' => true, 'status' => 'active'])->id;
        $this->loanRecvId = $mk('1209', 'Staff loans', 'asset', 'debit');
        PayrollSetting::current()->update([
            'basic_salary_account_id' => $mk('5101', 'Basic', 'expense', 'debit'),
            'housing_account_id' => $mk('5102', 'Housing', 'expense', 'debit'),
            'transport_account_id' => $mk('5103', 'Transport', 'expense', 'debit'),
            'other_earnings_account_id' => $mk('5104', 'Other', 'expense', 'debit'),
            'gosi_expense_account_id' => $mk('5105', 'GOSI exp', 'expense', 'debit'),
            'eosb_expense_account_id' => $mk('5106', 'EOSB exp', 'expense', 'debit'),
            'gosi_payable_account_id' => $mk('2301', 'GOSI pay', 'liability', 'credit'),
            'eosb_provision_account_id' => $mk('2302', 'EOSB prov', 'liability', 'credit'),
            'net_payable_account_id' => $mk('2303', 'Net pay', 'liability', 'credit'),
            'loan_receivable_account_id' => $this->loanRecvId,
            'loan_bank_account_id' => $mk('1001', 'Bank', 'asset', 'debit'),
        ]);

        $this->empId = PayrollEmployee::query()->create(['employee_code' => 'E1', 'name' => 'Ahmed', 'is_saudi' => false, 'basic_salary' => 9000, 'housing_allowance' => 1000, 'join_date' => '2024-01-01', 'status' => 'active', 'created_by' => $u->id])->id;

        $year = FiscalYear::query()->create(['code' => 'FY2026', 'name' => 'FY2026', 'start_date' => '2026-01-01', 'end_date' => '2026-12-31', 'status' => 'open']);
        FiscalPeriod::query()->create(['fiscal_year_id' => $year->id, 'period_number' => 3, 'name' => 'Mar', 'start_date' => '2026-03-01', 'end_date' => '2026-03-31', 'status' => 'open']);
    }

    private function actAs(string $role): void
    {
        $u = User::factory()->create();
        $u->roles()->attach(Role::query()->where('name', $role)->firstOrFail());
        Sanctum::actingAs($u->fresh());
    }

    public function test_disbursement_posts_receivable_and_payroll_recovers_instalment(): void
    {
        $this->actAs('finance-manager');

        // Disburse a 6,000 loan, 1,000/month.
        $loan = $this->postJson('/api/v1/payroll/loans', [
            'payroll_employee_id' => $this->empId, 'loan_type' => 'loan', 'principal' => 6000, 'monthly_installment' => 1000, 'disbursed_date' => '2026-03-01',
        ])->assertCreated()->json('data');
        $this->assertEqualsWithDelta(6000, (float) $loan['outstanding_balance'], 0.01);

        // Run payroll: gross 10,000, expat GOSI 0, loan recovery 1,000 → net 9,000.
        $id = $this->postJson('/api/v1/payroll/runs', ['period_year' => 2026, 'period_month' => 3, 'pay_date' => '2026-03-28'])->assertCreated()->json('data.id');
        $run = PayrollRun::query()->with('lines')->find($id);
        $line = $run->lines->first();
        $this->assertEqualsWithDelta(1000, (float) $line->other_deductions, 0.01);
        $this->assertEqualsWithDelta(9000, (float) $line->net_pay, 0.01);

        // Post → loan balance drops to 5,000 and loan receivable is credited 1,000.
        $this->postJson("/api/v1/payroll/runs/{$id}/approve")->assertOk();
        $this->postJson("/api/v1/payroll/runs/{$id}/post")->assertOk();

        $this->assertEqualsWithDelta(5000, (float) EmployeeLoan::query()->find($loan['id'])->outstanding_balance, 0.01);

        $batch = PayrollRun::query()->find($id)->batch_number;
        $rows = GlTransaction::query()->where('batch_number', $batch)->get();
        $this->assertEqualsWithDelta($rows->sum('base_debit'), $rows->sum('base_credit'), 0.01);
        $this->assertEqualsWithDelta(1000, $rows->firstWhere('account_id', $this->loanRecvId)->base_credit, 0.01);
    }

    public function test_final_instalment_settles_the_loan(): void
    {
        $this->actAs('finance-manager');

        // 800 loan, 1,000 instalment → recovers only 800 (capped), settles.
        $loan = EmployeeLoan::query()->create(['reference' => 'LN-1', 'payroll_employee_id' => $this->empId, 'loan_type' => 'advance', 'principal' => 800, 'monthly_installment' => 1000, 'outstanding_balance' => 800, 'disbursed_date' => '2026-02-01', 'status' => 'active', 'created_by' => User::factory()->create()->id]);

        $id = $this->postJson('/api/v1/payroll/runs', ['period_year' => 2026, 'period_month' => 3, 'pay_date' => '2026-03-28'])->assertCreated()->json('data.id');
        $this->postJson("/api/v1/payroll/runs/{$id}/approve")->assertOk();
        $this->postJson("/api/v1/payroll/runs/{$id}/post")->assertOk();

        $loan->refresh();
        $this->assertEqualsWithDelta(0, (float) $loan->outstanding_balance, 0.01);
        $this->assertSame('settled', $loan->status);
    }
}
