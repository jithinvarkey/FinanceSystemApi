<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\CostCenter;
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
 * W4 — Workforce cost analytics.
 */
final class WorkforceAnalyticsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RbacSeeder::class);

        $u = User::factory()->create();
        $cc = CostCenter::query()->create(['code' => 'CC-OPS', 'name' => 'Operations', 'status' => 'active'])->id;
        $emp = PayrollEmployee::query()->create(['employee_code' => 'E1', 'name' => 'Ahmed', 'is_saudi' => true, 'basic_salary' => 10000, 'housing_allowance' => 2000, 'cost_center_id' => $cc, 'join_date' => '2024-01-01', 'status' => 'active', 'created_by' => $u->id]);

        // A posted run with one payslip: gross 12,000, employer GOSI 1,410, EOSB 583.33 → total cost 13,993.33.
        $run = PayrollRun::query()->create(['reference' => 'PAY-1', 'period_year' => 2026, 'period_month' => 4, 'pay_date' => '2026-04-28', 'gross_total' => 12000, 'net_total' => 10830, 'status' => 'posted', 'created_by' => $u->id]);
        PayrollRunLine::query()->create(['payroll_run_id' => $run->id, 'payroll_employee_id' => $emp->id, 'employee_name' => 'Ahmed', 'basic' => 10000, 'housing' => 2000, 'gross' => 12000, 'gosi_employee' => 1170, 'gosi_employer' => 1410, 'eosb_accrual' => 583.33, 'net_pay' => 10830]);
    }

    private function actAs(string $role): void
    {
        $u = User::factory()->create();
        $u->roles()->attach(Role::query()->where('name', $role)->firstOrFail());
        Sanctum::actingAs($u->fresh());
    }

    public function test_analytics_summarises_workforce_cost_by_cost_center(): void
    {
        $this->actAs('finance-manager');

        $data = $this->getJson('/api/v1/payroll/analytics?year=2026')->assertOk()->json('data');

        $this->assertEqualsWithDelta(12000, $data['summary']['gross'], 0.01);
        $this->assertEqualsWithDelta(13993.33, $data['summary']['total_cost'], 0.01);   // gross + employer GOSI + EOSB
        $this->assertSame(1, $data['summary']['headcount']);

        $ops = collect($data['by_cost_center'])->firstWhere('cost_center', 'Operations');
        $this->assertSame(1, $ops['headcount']);
        $this->assertEqualsWithDelta(13993.33, $ops['total_cost'], 0.01);

        $this->assertSame(4, $data['monthly_trend'][0]['month']);
    }
}
