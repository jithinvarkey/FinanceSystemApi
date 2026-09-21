<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\ChartOfAccount;
use App\Models\EmployeeBenefit;
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
 * W2 — Employee benefits: air-ticket accrual + cost recording.
 */
final class EmployeeBenefitTest extends TestCase
{
    use RefreshDatabase;

    private int $provId;
    private int $payableId;
    private int $empId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RbacSeeder::class);

        $u = User::factory()->create();
        $mk = fn (string $code, string $name, string $type, string $nb): int => ChartOfAccount::query()->create(['code' => $code, 'name' => $name, 'account_type' => $type, 'normal_balance' => $nb, 'level' => 1, 'is_postable' => true, 'status' => 'active'])->id;
        $this->provId = $mk('2401', 'Air ticket provision', 'liability', 'credit');
        $this->payableId = $mk('2402', 'Benefit payable', 'liability', 'credit');
        PayrollSetting::current()->update([
            'airticket_expense_account_id' => $mk('5401', 'Air ticket expense', 'expense', 'debit'),
            'airticket_provision_account_id' => $this->provId,
            'benefit_expense_account_id' => $mk('5402', 'Benefit expense', 'expense', 'debit'),
            'benefit_payable_account_id' => $this->payableId,
        ]);

        $this->empId = PayrollEmployee::query()->create(['employee_code' => 'E1', 'name' => 'Ahmed', 'is_saudi' => false, 'basic_salary' => 8000, 'join_date' => '2024-01-01', 'status' => 'active', 'created_by' => $u->id])->id;

        $year = FiscalYear::query()->create(['code' => 'FY2026', 'name' => 'FY2026', 'start_date' => '2026-01-01', 'end_date' => '2026-12-31', 'status' => 'open']);
        FiscalPeriod::query()->create(['fiscal_year_id' => $year->id, 'period_number' => 6, 'name' => 'Jun', 'start_date' => '2026-06-01', 'end_date' => '2026-06-30', 'status' => 'open']);
    }

    private function actAs(string $role): void
    {
        $u = User::factory()->create();
        $u->roles()->attach(Role::query()->where('name', $role)->firstOrFail());
        Sanctum::actingAs($u->fresh());
    }

    public function test_airticket_accrual_posts_provision_and_increments_accrued(): void
    {
        $this->actAs('finance-manager');

        $benefit = $this->postJson('/api/v1/payroll/benefits', [
            'payroll_employee_id' => $this->empId, 'benefit_type' => 'air_ticket', 'annual_amount' => 6000,
        ])->assertCreated()->json('data');

        // Accrue June → 6000/12 = 500.
        $this->postJson('/api/v1/payroll/benefits/accrue', ['period_year' => 2026, 'period_month' => 6, 'date' => '2026-06-30'])
            ->assertOk()->assertJsonPath('data.accrued', 1)->assertJsonPath('data.amount', '500.00');

        $this->assertEqualsWithDelta(500, (float) EmployeeBenefit::query()->find($benefit['id'])->accrued_amount, 0.01);
        $this->assertEqualsWithDelta(500, GlTransaction::query()->where('account_id', $this->provId)->sum('base_credit'), 0.01);

        // Re-accruing the same month is a no-op.
        $this->postJson('/api/v1/payroll/benefits/accrue', ['period_year' => 2026, 'period_month' => 6, 'date' => '2026-06-30'])
            ->assertOk()->assertJsonPath('data.accrued', 0);
    }

    public function test_ticket_utilisation_releases_provision_then_expenses_excess(): void
    {
        $this->actAs('finance-manager');

        $benefit = EmployeeBenefit::query()->create(['payroll_employee_id' => $this->empId, 'benefit_type' => 'air_ticket', 'annual_amount' => 6000, 'accrued_amount' => 500, 'utilized_amount' => 0, 'status' => 'active', 'created_by' => User::factory()->create()->id]);

        // Book a 1,200 ticket: 500 from provision, 700 excess expense, 1,200 to payable.
        $this->postJson("/api/v1/payroll/benefits/{$benefit->id}/cost", ['amount' => 1200, 'cost_date' => '2026-06-15', 'description' => 'Annual ticket'])
            ->assertCreated()->assertJsonPath('data.kind', 'utilization');

        $batch = \App\Models\BenefitCost::query()->latest('id')->value('batch_number');
        $rows = GlTransaction::query()->where('batch_number', $batch)->get();
        $this->assertEqualsWithDelta($rows->sum('base_debit'), $rows->sum('base_credit'), 0.01);
        $this->assertEqualsWithDelta(500, $rows->firstWhere('account_id', $this->provId)->base_debit, 0.01);   // provision released
        $this->assertEqualsWithDelta(1200, $rows->firstWhere('account_id', $this->payableId)->base_credit, 0.01);
        $this->assertEqualsWithDelta(1200, (float) $benefit->fresh()->utilized_amount, 0.01);
    }
}
