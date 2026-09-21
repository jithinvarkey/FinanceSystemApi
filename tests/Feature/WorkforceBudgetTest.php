<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\PayrollEmployee;
use App\Models\PayrollRun;
use App\Models\PayrollRunLine;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * W8 — Workforce budget vs actual.
 */
final class WorkforceBudgetTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RbacSeeder::class);

        $u = User::factory()->create();
        $emp = PayrollEmployee::query()->create(['employee_code' => 'E1', 'name' => 'Ahmed', 'is_saudi' => true, 'basic_salary' => 10000, 'housing_allowance' => 2000, 'join_date' => '2024-01-01', 'status' => 'active', 'created_by' => $u->id]);

        // Posted regular run: gross 12,000.
        $run = PayrollRun::query()->create(['reference' => 'PAY-1', 'run_type' => 'regular', 'period_year' => 2026, 'period_month' => 4, 'pay_date' => '2026-04-28', 'gross_total' => 12000, 'status' => 'posted', 'created_by' => $u->id]);
        PayrollRunLine::query()->create(['payroll_run_id' => $run->id, 'payroll_employee_id' => $emp->id, 'employee_name' => 'Ahmed', 'gross' => 12000, 'gosi_employer' => 1410, 'eosb_accrual' => 583.33, 'net_pay' => 10830]);
    }

    private function actAs(string $role): void
    {
        $u = User::factory()->create();
        $u->roles()->attach(Role::query()->where('name', $role)->firstOrFail());
        Sanctum::actingAs($u->fresh());
    }

    public function test_budget_vs_actual_computes_variance_and_used_pct(): void
    {
        $this->actAs('finance-manager');

        // Set a salary budget of 15,000 for 2026.
        $this->putJson('/api/v1/payroll/budget', ['year' => 2026, 'budgets' => ['salary' => 15000, 'gosi' => 2000]])->assertOk();

        $data = $this->getJson('/api/v1/payroll/budget?year=2026')->assertOk()->json('data');

        $salary = collect($data['rows'])->firstWhere('category', 'salary');
        $this->assertEqualsWithDelta(15000, $salary['budget'], 0.01);
        $this->assertEqualsWithDelta(12000, $salary['actual'], 0.01);     // gross
        $this->assertEqualsWithDelta(3000, $salary['variance'], 0.01);    // 15,000 − 12,000
        $this->assertEqualsWithDelta(80, $salary['used_pct'], 0.1);       // 12,000 / 15,000

        $gosi = collect($data['rows'])->firstWhere('category', 'gosi');
        $this->assertEqualsWithDelta(1410, $gosi['actual'], 0.01);        // employer GOSI
    }
}
