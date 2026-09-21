<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\ChartOfAccount;
use App\Models\FiscalPeriod;
use App\Models\FiscalYear;
use App\Models\GlTransaction;
use App\Models\JournalEntry;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * P8 — Budgeting: annual targets per account vs GL actuals for the fiscal year.
 */
final class BudgetApiTest extends TestCase
{
    use RefreshDatabase;

    private int $yearId;
    private int $periodId;
    private int $expenseId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RbacSeeder::class);

        $year = FiscalYear::query()->create(['code' => 'FY2026', 'name' => 'FY2026', 'start_date' => '2026-01-01', 'end_date' => '2026-12-31', 'status' => 'open']);
        $this->yearId = $year->id;
        $this->periodId = FiscalPeriod::query()->create(['fiscal_year_id' => $year->id, 'period_number' => 6, 'name' => 'Jun 2026', 'start_date' => '2026-06-01', 'end_date' => '2026-06-30', 'status' => 'open'])->id;
        $this->expenseId = ChartOfAccount::query()->create(['code' => '510101', 'name' => 'Office expense', 'account_type' => 'expense', 'normal_balance' => 'debit', 'level' => 1, 'is_postable' => true, 'status' => 'active'])->id;
    }

    private function actingAsRole(string $role): void
    {
        $user = User::factory()->create();
        $user->roles()->attach(Role::query()->where('name', $role)->firstOrFail());
        Sanctum::actingAs($user->fresh());
    }

    public function test_create_and_vs_actual(): void
    {
        $this->actingAsRole('accountant'); // general-ledger.manage

        $budgetId = $this->postJson('/api/v1/budgets', [
            'name' => '2026 Opex', 'fiscal_year_id' => $this->yearId, 'status' => 'active',
            'lines' => [['account_id' => $this->expenseId, 'annual_amount' => 10000]],
        ])->assertCreated()->json('data.id');

        // Book 3,000 of actual expense in the year.
        $journal = JournalEntry::query()->create([
            'journal_number' => 'JV-1', 'journal_date' => '2026-06-10', 'description' => 'Office spend', 'status' => 'posted', 'currency_code' => 'SAR',
            'total_debit' => 3000, 'total_credit' => 3000, 'fiscal_period_id' => $this->periodId, 'created_by' => User::query()->value('id'),
        ]);
        GlTransaction::query()->create(['source_type' => JournalEntry::class, 'source_id' => $journal->id, 'fiscal_period_id' => $this->periodId, 'transaction_date' => '2026-06-10', 'account_id' => $this->expenseId, 'debit' => 3000, 'credit' => 0, 'base_debit' => 3000, 'base_credit' => 0, 'currency_code' => 'SAR', 'exchange_rate' => 1, 'batch_number' => 'B1', 'posted_by' => User::query()->value('id'), 'posted_at' => now()]);

        $this->getJson("/api/v1/budgets/{$budgetId}/vs-actual")
            ->assertOk()
            ->assertJsonPath('data.totals.budget', '10000.00')
            ->assertJsonPath('data.totals.actual', '3000.00')
            ->assertJsonPath('data.totals.variance', '7000.00');
    }

    public function test_create_requires_manage(): void
    {
        $this->actingAsRole('auditor');

        $this->postJson('/api/v1/budgets', [
            'name' => 'X', 'fiscal_year_id' => $this->yearId,
            'lines' => [['account_id' => $this->expenseId, 'annual_amount' => 100]],
        ])->assertStatus(403);
    }
}
