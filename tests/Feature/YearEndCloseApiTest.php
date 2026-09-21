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
 * P10 — Year-end close: zero the P&L accounts and roll the net result to
 * retained earnings, then lock the year.
 */
final class YearEndCloseApiTest extends TestCase
{
    use RefreshDatabase;

    private int $yearId;
    private int $periodId;
    private int $revenueId;
    private int $expenseId;
    private int $retainedId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RbacSeeder::class);

        $year = FiscalYear::query()->create(['code' => 'FY2026', 'name' => 'FY2026', 'start_date' => '2026-01-01', 'end_date' => '2026-12-31', 'status' => 'open']);
        $this->yearId = $year->id;
        $this->periodId = FiscalPeriod::query()->create(['fiscal_year_id' => $year->id, 'period_number' => 12, 'name' => 'Dec 2026', 'start_date' => '2026-12-01', 'end_date' => '2026-12-31', 'status' => 'open'])->id;

        $this->revenueId = ChartOfAccount::query()->create(['code' => '410101', 'name' => 'Commission income', 'account_type' => 'revenue', 'normal_balance' => 'credit', 'level' => 1, 'is_postable' => true, 'status' => 'active'])->id;
        $this->expenseId = ChartOfAccount::query()->create(['code' => '510101', 'name' => 'Salaries', 'account_type' => 'expense', 'normal_balance' => 'debit', 'level' => 1, 'is_postable' => true, 'status' => 'active'])->id;
        $this->retainedId = ChartOfAccount::query()->create(['code' => '21103', 'name' => 'Retained earnings', 'account_type' => 'equity', 'normal_balance' => 'credit', 'level' => 1, 'is_postable' => true, 'status' => 'active'])->id;

        $this->bookGl($this->revenueId, 0, 5000);   // revenue credit 5,000
        $this->bookGl($this->expenseId, 2000, 0);   // expense debit 2,000  → net profit 3,000
    }

    private function bookGl(int $accountId, float $debit, float $credit): void
    {
        $u = User::query()->value('id') ?? User::factory()->create()->id;
        $j = JournalEntry::query()->create(['journal_number' => 'JV-'.$accountId, 'journal_date' => '2026-06-10', 'description' => 'activity', 'status' => 'posted', 'currency_code' => 'SAR', 'total_debit' => $debit, 'total_credit' => $credit, 'fiscal_period_id' => $this->periodId, 'created_by' => $u]);
        GlTransaction::query()->create(['source_type' => JournalEntry::class, 'source_id' => $j->id, 'fiscal_period_id' => $this->periodId, 'transaction_date' => '2026-06-10', 'account_id' => $accountId, 'debit' => $debit, 'credit' => $credit, 'base_debit' => $debit, 'base_credit' => $credit, 'currency_code' => 'SAR', 'exchange_rate' => 1, 'batch_number' => 'B', 'posted_by' => $u, 'posted_at' => now()]);
    }

    private function actingAsRole(string $role): void
    {
        $user = User::factory()->create();
        $user->roles()->attach(Role::query()->where('name', $role)->firstOrFail());
        Sanctum::actingAs($user->fresh());
    }

    public function test_preview_reports_net_profit(): void
    {
        $this->actingAsRole('finance-manager');

        $this->getJson('/api/v1/general-ledger/year-end/preview?fiscal_year_id='.$this->yearId)
            ->assertOk()
            ->assertJsonPath('data.revenue', '5000.00')
            ->assertJsonPath('data.expenses', '2000.00')
            ->assertJsonPath('data.net_profit', '3000.00');
    }

    public function test_close_rolls_net_to_retained_earnings_and_locks_year(): void
    {
        $this->actingAsRole('finance-manager'); // general-ledger.post

        $res = $this->postJson('/api/v1/general-ledger/year-end/close', [
            'fiscal_year_id' => $this->yearId,
            'retained_earnings_account_id' => $this->retainedId,
        ])->assertCreated();

        $journalId = $res->json('data.id');
        $rows = GlTransaction::query()->where('source_type', JournalEntry::class)->where('source_id', $journalId)->get();

        // Dr revenue 5,000 / Cr expense 2,000 / Cr retained earnings 3,000.
        $this->assertEqualsWithDelta(5000, (float) $rows->firstWhere('account_id', $this->revenueId)->base_debit, 0.01);
        $this->assertEqualsWithDelta(2000, (float) $rows->firstWhere('account_id', $this->expenseId)->base_credit, 0.01);
        $this->assertEqualsWithDelta(3000, (float) $rows->firstWhere('account_id', $this->retainedId)->base_credit, 0.01);
        $this->assertEqualsWithDelta(5000, $rows->sum('base_debit'), 0.01);
        $this->assertEqualsWithDelta(5000, $rows->sum('base_credit'), 0.01);

        $this->assertSame('closed', FiscalYear::query()->find($this->yearId)->status);
        $this->assertSame('closed', FiscalPeriod::query()->find($this->periodId)->status->value);
    }

    public function test_cannot_close_twice(): void
    {
        $this->actingAsRole('finance-manager');
        $payload = ['fiscal_year_id' => $this->yearId, 'retained_earnings_account_id' => $this->retainedId];

        $this->postJson('/api/v1/general-ledger/year-end/close', $payload)->assertCreated();
        $this->postJson('/api/v1/general-ledger/year-end/close', $payload)->assertStatus(422);
    }
}
