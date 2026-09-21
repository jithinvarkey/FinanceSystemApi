<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Budget;
use App\Models\BudgetLine;
use App\Models\ChartOfAccount;
use App\Models\FiscalYear;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * E9 — Budget revisions & transfers.
 */
final class BudgetRevisionTest extends TestCase
{
    use RefreshDatabase;

    private Budget $budget;
    private BudgetLine $lineA;
    private BudgetLine $lineB;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RbacSeeder::class);

        $u = User::factory()->create();
        $year = FiscalYear::query()->create(['code' => 'FY2026', 'name' => 'FY2026', 'start_date' => '2026-01-01', 'end_date' => '2026-12-31', 'status' => 'open']);
        $accA = ChartOfAccount::query()->create(['code' => '5001', 'name' => 'Marketing', 'account_type' => 'expense', 'normal_balance' => 'debit', 'level' => 1, 'is_postable' => true, 'status' => 'active'])->id;
        $accB = ChartOfAccount::query()->create(['code' => '5002', 'name' => 'Travel', 'account_type' => 'expense', 'normal_balance' => 'debit', 'level' => 1, 'is_postable' => true, 'status' => 'active'])->id;

        $this->budget = Budget::query()->create(['name' => 'FY2026 OpEx', 'fiscal_year_id' => $year->id, 'status' => 'active', 'created_by' => $u->id]);
        $this->lineA = BudgetLine::query()->create(['budget_id' => $this->budget->id, 'account_id' => $accA, 'annual_amount' => 10000]);
        $this->lineB = BudgetLine::query()->create(['budget_id' => $this->budget->id, 'account_id' => $accB, 'annual_amount' => 4000]);
    }

    private function actAs(string $role): void
    {
        $u = User::factory()->create();
        $u->roles()->attach(Role::query()->where('name', $role)->firstOrFail());
        Sanctum::actingAs($u->fresh());
    }

    public function test_revision_restates_a_line_and_logs_the_delta(): void
    {
        $this->actAs('finance-manager');

        $this->postJson("/api/v1/budgets/{$this->budget->id}/revise", [
            'budget_line_id' => $this->lineA->id, 'new_amount' => 12500, 'reason' => 'Q2 campaign uplift',
        ])->assertCreated();

        $this->assertEqualsWithDelta(12500, (float) $this->lineA->fresh()->annual_amount, 0.01);
        $this->assertDatabaseHas('budget_revisions', ['budget_line_id' => $this->lineA->id, 'type' => 'revision', 'delta' => 2500.00]);
    }

    public function test_transfer_moves_budget_between_two_lines(): void
    {
        $this->actAs('finance-manager');

        $this->postJson("/api/v1/budgets/{$this->budget->id}/transfer", [
            'from_line_id' => $this->lineA->id, 'to_line_id' => $this->lineB->id, 'amount' => 3000, 'reason' => 'Reallocate to travel',
        ])->assertCreated();

        $this->assertEqualsWithDelta(7000, (float) $this->lineA->fresh()->annual_amount, 0.01);
        $this->assertEqualsWithDelta(7000, (float) $this->lineB->fresh()->annual_amount, 0.01);
        $this->assertDatabaseHas('budget_revisions', ['budget_line_id' => $this->lineA->id, 'type' => 'transfer', 'delta' => -3000.00, 'counterpart_line_id' => $this->lineB->id]);
        $this->assertDatabaseHas('budget_revisions', ['budget_line_id' => $this->lineB->id, 'type' => 'transfer', 'delta' => 3000.00, 'counterpart_line_id' => $this->lineA->id]);
    }

    public function test_transfer_is_rejected_when_source_lacks_budget(): void
    {
        $this->actAs('finance-manager');

        $this->postJson("/api/v1/budgets/{$this->budget->id}/transfer", [
            'from_line_id' => $this->lineB->id, 'to_line_id' => $this->lineA->id, 'amount' => 9000, 'reason' => 'too much',
        ])->assertStatus(422);

        $this->assertEqualsWithDelta(4000, (float) $this->lineB->fresh()->annual_amount, 0.01);
    }
}
