<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\ChartOfAccount;
use App\Models\FiscalPeriod;
use App\Models\FiscalYear;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * P11 — Trial Balance / Income Statement / Balance Sheet from the GL.
 */
final class FinancialStatementTest extends TestCase
{
    use RefreshDatabase;

    private int $bankId;

    private int $revenueId;

    private int $expenseId;

    private int $periodId;

    private int $userId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed([RbacSeeder::class]);

        $u = User::factory()->create();
        $this->userId = $u->id;
        $this->bankId = ChartOfAccount::query()->create(['code' => '1101', 'name' => 'Bank', 'account_type' => 'asset', 'normal_balance' => 'debit', 'level' => 1, 'is_postable' => true, 'is_bank_account' => true, 'status' => 'active'])->id;
        $this->revenueId = ChartOfAccount::query()->create(['code' => '3001', 'name' => 'Sales', 'account_type' => 'revenue', 'normal_balance' => 'credit', 'level' => 1, 'is_postable' => true, 'status' => 'active'])->id;
        $this->expenseId = ChartOfAccount::query()->create(['code' => '4001', 'name' => 'Rent', 'account_type' => 'expense', 'normal_balance' => 'debit', 'level' => 1, 'is_postable' => true, 'status' => 'active'])->id;

        $year = FiscalYear::query()->create(['code' => 'FY2026', 'name' => 'FY2026', 'start_date' => '2026-01-01', 'end_date' => '2026-12-31', 'status' => 'open']);
        $this->periodId = FiscalPeriod::query()->create(['fiscal_year_id' => $year->id, 'period_number' => 3, 'name' => 'Mar 2026', 'start_date' => '2026-03-01', 'end_date' => '2026-03-31', 'status' => 'open'])->id;

        // A cash sale (Dr bank 1,000 / Cr sales 1,000) and an expense (Dr rent 300 / Cr bank 300).
        $this->glRow('GLB-1', '2026-03-10', $this->bankId, 1000, 0);
        $this->glRow('GLB-1', '2026-03-10', $this->revenueId, 0, 1000);
        $this->glRow('GLB-2', '2026-03-15', $this->expenseId, 300, 0);
        $this->glRow('GLB-2', '2026-03-15', $this->bankId, 0, 300);
    }

    private function glRow(string $batch, string $date, int $accountId, float $debit, float $credit): void
    {
        DB::table('gl_transactions')->insert([
            'batch_number' => $batch, 'fiscal_period_id' => $this->periodId, 'transaction_date' => $date,
            'account_id' => $accountId, 'currency_code' => 'SAR', 'exchange_rate' => 1,
            'debit' => $debit, 'credit' => $credit, 'base_debit' => $debit, 'base_credit' => $credit,
            'source_type' => 'App\\Models\\JournalEntry', 'source_id' => 1, 'posted_by' => $this->userId,
            'posted_at' => now(), 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function actingAsViewer(): void
    {
        $user = User::factory()->create();
        $user->roles()->attach(Role::query()->where('name', 'auditor')->firstOrFail());
        Sanctum::actingAs($user->fresh());
    }

    public function test_trial_balance_balances(): void
    {
        $this->actingAsViewer();

        $data = $this->getJson('/api/v1/general-ledger/reports/trial-balance')->assertOk()->json('data');

        $this->assertTrue($data['balanced']);
        // Debit side: bank 700 + rent 300 = 1,000. Credit side: sales 1,000.
        $this->assertEqualsWithDelta(1000, (float) $data['totals']['debit'], 0.01);
        $this->assertEqualsWithDelta(1000, (float) $data['totals']['credit'], 0.01);
        $bank = collect($data['rows'])->firstWhere('code', '1101');
        $this->assertEqualsWithDelta(700, (float) $bank['debit'], 0.01);
    }

    public function test_cash_flow_reconciles(): void
    {
        $this->actingAsViewer();

        $data = $this->getJson('/api/v1/general-ledger/reports/cash-flow')->assertOk()->json('data');

        // Cash sale +1,000 (operating) and rent −300 (operating) ⇒ net +700 = closing cash.
        $this->assertTrue($data['reconciles']);
        $this->assertEqualsWithDelta(700, (float) $data['net_change'], 0.01);
        $this->assertEqualsWithDelta(700, (float) $data['closing_cash'], 0.01);
        $this->assertEqualsWithDelta(700, (float) $data['operating_total'], 0.01);
    }

    public function test_account_ledger_has_running_balance(): void
    {
        $this->actingAsViewer();

        $data = $this->getJson('/api/v1/general-ledger/reports/account-ledger?account_id='.$this->bankId)
            ->assertOk()->json('data');

        $this->assertSame('1101', $data['account']['code']);
        $this->assertCount(2, $data['rows']);
        $this->assertEqualsWithDelta(1000, (float) $data['rows'][0]['balance'], 0.01); // after +1,000
        $this->assertEqualsWithDelta(700, (float) $data['rows'][1]['balance'], 0.01);  // after −300
        $this->assertEqualsWithDelta(700, (float) $data['closing'], 0.01);
    }

    public function test_income_statement_nets_revenue_less_expenses(): void
    {
        $this->actingAsViewer();

        $data = $this->getJson('/api/v1/general-ledger/reports/income-statement')->assertOk()->json('data');

        $this->assertEqualsWithDelta(1000, (float) $data['revenue_total'], 0.01);
        $this->assertEqualsWithDelta(300, (float) $data['expenses_total'], 0.01);
        $this->assertEqualsWithDelta(700, (float) $data['net_profit'], 0.01);
    }

    public function test_balance_sheet_balances_with_current_earnings(): void
    {
        $this->actingAsViewer();

        $data = $this->getJson('/api/v1/general-ledger/reports/balance-sheet')->assertOk()->json('data');

        $this->assertTrue($data['balanced']);
        $this->assertEqualsWithDelta(700, (float) $data['assets_total'], 0.01);          // bank
        $this->assertEqualsWithDelta(700, (float) $data['current_earnings'], 0.01);       // net profit in equity
        $this->assertEqualsWithDelta(700, (float) $data['liabilities_equity_total'], 0.01);
    }
}
