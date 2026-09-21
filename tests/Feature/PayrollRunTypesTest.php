<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\ChartOfAccount;
use App\Models\FiscalPeriod;
use App\Models\FiscalYear;
use App\Models\GlTransaction;
use App\Models\PayrollEmployee;
use App\Models\PayrollSetting;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * W6 — off-cycle bonus + final-settlement run types.
 */
final class PayrollRunTypesTest extends TestCase
{
    use RefreshDatabase;

    private int $provId;
    private int $netId;
    private int $empId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RbacSeeder::class);

        $u = User::factory()->create();
        $mk = fn (string $code, string $name, string $type, string $nb): int => ChartOfAccount::query()->create(['code' => $code, 'name' => $name, 'account_type' => $type, 'normal_balance' => $nb, 'level' => 1, 'is_postable' => true, 'status' => 'active'])->id;
        $this->provId = $mk('2302', 'EOSB provision', 'liability', 'credit');
        $this->netId = $mk('2303', 'Net payable', 'liability', 'credit');
        PayrollSetting::current()->update([
            'basic_salary_account_id' => $mk('5101', 'Basic', 'expense', 'debit'),
            'other_earnings_account_id' => $mk('5104', 'Other/bonus', 'expense', 'debit'),
            'gosi_expense_account_id' => $mk('5105', 'GOSI', 'expense', 'debit'),
            'eosb_expense_account_id' => $mk('5106', 'EOSB exp', 'expense', 'debit'),
            'housing_account_id' => $mk('5102', 'Housing', 'expense', 'debit'),
            'transport_account_id' => $mk('5103', 'Transport', 'expense', 'debit'),
            'gosi_payable_account_id' => $mk('2301', 'GOSI pay', 'liability', 'credit'),
            'eosb_provision_account_id' => $this->provId,
            'net_payable_account_id' => $this->netId,
            'loan_receivable_account_id' => $mk('1209', 'Loans', 'asset', 'debit'),
        ]);

        // Joined exactly 4 years before the settlement date → first-5-years bracket.
        $this->empId = PayrollEmployee::query()->create(['employee_code' => 'E1', 'name' => 'Ahmed', 'is_saudi' => false, 'basic_salary' => 9000, 'housing_allowance' => 1000, 'join_date' => '2022-03-01', 'status' => 'active', 'created_by' => $u->id])->id;

        $year = FiscalYear::query()->create(['code' => 'FY2026', 'name' => 'FY2026', 'start_date' => '2026-01-01', 'end_date' => '2026-12-31', 'status' => 'open']);
        FiscalPeriod::query()->create(['fiscal_year_id' => $year->id, 'period_number' => 3, 'name' => 'Mar', 'start_date' => '2026-03-01', 'end_date' => '2026-03-31', 'status' => 'open']);
    }

    private function actAs(string $role): void
    {
        $u = User::factory()->create();
        $u->roles()->attach(Role::query()->where('name', $role)->firstOrFail());
        Sanctum::actingAs($u->fresh());
    }

    public function test_off_cycle_bonus_run_posts_bonus_to_net_payable(): void
    {
        $this->actAs('finance-manager');

        $id = $this->postJson('/api/v1/payroll/runs/off-cycle', [
            'period_year' => 2026, 'period_month' => 3, 'pay_date' => '2026-03-20',
            'items' => [['payroll_employee_id' => $this->empId, 'amount' => 5000]],
        ])->assertCreated()->assertJsonPath('data.run_type', 'off_cycle')->json('data.id');

        $this->postJson("/api/v1/payroll/runs/{$id}/approve")->assertOk();
        $this->postJson("/api/v1/payroll/runs/{$id}/post")->assertOk()->assertJsonPath('data.status', 'posted');

        $batch = \App\Models\PayrollRun::query()->find($id)->batch_number;
        $rows = GlTransaction::query()->where('batch_number', $batch)->get();
        $this->assertEqualsWithDelta($rows->sum('base_debit'), $rows->sum('base_credit'), 0.01);
        $this->assertEqualsWithDelta(5000, $rows->firstWhere('account_id', $this->netId)->base_credit, 0.01);
    }

    public function test_final_settlement_pays_eosb_gratuity_and_deactivates_employee(): void
    {
        $this->actAs('finance-manager');

        // 4 years service, wage = basic+housing = 10,000 → ½ month × 4 yrs = 20,000.
        $data = $this->postJson('/api/v1/payroll/runs/final-settlement', [
            'payroll_employee_id' => $this->empId, 'last_day' => '2026-03-01', 'pay_date' => '2026-03-05',
        ])->assertCreated()->assertJsonPath('data.run_type', 'final_settlement')->json('data');

        $this->assertEqualsWithDelta(20000, (float) $data['eosb_total'], 1.0);
        $this->assertSame('posted', $data['status']);

        // Employee deactivated.
        $this->assertSame('inactive', PayrollEmployee::query()->find($this->empId)->status);

        $rows = GlTransaction::query()->where('batch_number', $data['batch_number'])->get();
        $this->assertEqualsWithDelta($rows->sum('base_debit'), $rows->sum('base_credit'), 0.01);
        // Gratuity debited to the EOSB provision (releasing the liability).
        $this->assertGreaterThan(19000, $rows->firstWhere('account_id', $this->provId)->base_debit);
    }
}
