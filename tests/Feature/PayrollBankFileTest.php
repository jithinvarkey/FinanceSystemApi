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
 * W7 — payroll bank-file generation.
 */
final class PayrollBankFileTest extends TestCase
{
    use RefreshDatabase;

    private int $runId;
    private int $withIban;
    private int $noIban;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RbacSeeder::class);

        $u = User::factory()->create();
        $this->withIban = PayrollEmployee::query()->create(['employee_code' => 'E1', 'name' => 'Ahmed', 'is_saudi' => true, 'basic_salary' => 10000, 'iban' => 'SA0380000000608010167519', 'join_date' => '2024-01-01', 'status' => 'active', 'created_by' => $u->id])->id;
        $this->noIban = PayrollEmployee::query()->create(['employee_code' => 'E2', 'name' => 'John', 'is_saudi' => false, 'basic_salary' => 8000, 'join_date' => '2024-01-01', 'status' => 'active', 'created_by' => $u->id])->id;

        $run = PayrollRun::query()->create(['reference' => 'PAY-1', 'period_year' => 2026, 'period_month' => 1, 'pay_date' => '2026-01-28', 'net_total' => 18000, 'status' => 'posted', 'created_by' => $u->id]);
        $this->runId = $run->id;
        PayrollRunLine::query()->create(['payroll_run_id' => $run->id, 'payroll_employee_id' => $this->withIban, 'employee_name' => 'Ahmed', 'gross' => 10000, 'net_pay' => 10000]);
        PayrollRunLine::query()->create(['payroll_run_id' => $run->id, 'payroll_employee_id' => $this->noIban, 'employee_name' => 'John', 'gross' => 8000, 'net_pay' => 8000]);
    }

    private function actAs(string $role): void
    {
        $u = User::factory()->create();
        $u->roles()->attach(Role::query()->where('name', $role)->firstOrFail());
        Sanctum::actingAs($u->fresh());
    }

    public function test_bank_file_lists_beneficiaries_and_flags_missing_iban(): void
    {
        $this->actAs('finance-manager');

        $data = $this->getJson("/api/v1/payroll/runs/{$this->runId}/bank-file")->assertOk()->json('data');

        $this->assertCount(2, $data['rows']);
        $this->assertEqualsWithDelta(18000, $data['total'], 0.01);
        $ahmed = collect($data['rows'])->firstWhere('name', 'Ahmed');
        $this->assertSame('SA0380000000608010167519', $ahmed['iban']);
        $this->assertEqualsWithDelta(10000, $ahmed['amount'], 0.01);

        // John has no IBAN → flagged.
        $this->assertContains('John', $data['missing_iban']);
    }

    public function test_bank_file_refused_for_a_draft_run(): void
    {
        $this->actAs('finance-manager');
        PayrollRun::query()->whereKey($this->runId)->update(['status' => 'draft']);

        $this->getJson("/api/v1/payroll/runs/{$this->runId}/bank-file")->assertStatus(422);
    }
}
