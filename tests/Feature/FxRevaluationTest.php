<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\ChartOfAccount;
use App\Models\Currency;
use App\Models\ExchangeRate;
use App\Models\FiscalPeriod;
use App\Models\FiscalYear;
use App\Models\GlTransaction;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * F22 — FX revaluation of foreign-currency monetary balances.
 */
final class FxRevaluationTest extends TestCase
{
    use RefreshDatabase;

    private int $bankId;
    private int $fxGainLossId;
    private int $periodId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RbacSeeder::class);

        Currency::query()->create(['code' => 'SAR', 'name' => 'Saudi Riyal', 'symbol' => 'SR', 'decimal_places' => 2, 'is_base' => true, 'status' => 'active']);
        $usd = Currency::query()->create(['code' => 'USD', 'name' => 'US Dollar', 'symbol' => '$', 'decimal_places' => 2, 'is_base' => false, 'status' => 'active']);

        $this->bankId = ChartOfAccount::query()->create(['code' => '1011', 'name' => 'USD Bank', 'account_type' => 'asset', 'normal_balance' => 'debit', 'level' => 1, 'is_postable' => true, 'status' => 'active'])->id;
        $this->fxGainLossId = ChartOfAccount::query()->create(['code' => '4900', 'name' => 'FX gain/loss', 'account_type' => 'revenue', 'normal_balance' => 'credit', 'level' => 1, 'is_postable' => true, 'status' => 'active'])->id;
        $equity = ChartOfAccount::query()->create(['code' => '3001', 'name' => 'Opening equity', 'account_type' => 'equity', 'normal_balance' => 'credit', 'level' => 1, 'is_postable' => true, 'status' => 'active'])->id;

        $year = FiscalYear::query()->create(['code' => 'FY2026', 'name' => 'FY2026', 'start_date' => '2026-01-01', 'end_date' => '2026-12-31', 'status' => 'open']);
        $this->periodId = FiscalPeriod::query()->create(['fiscal_year_id' => $year->id, 'period_number' => 3, 'name' => 'Mar 2026', 'start_date' => '2026-03-01', 'end_date' => '2026-03-31', 'status' => 'open'])->id;

        // USD bank holds $1,000 booked at rate 3.70 → base 3,700.
        $u = User::factory()->create();
        $this->glRow($this->bankId, 'USD', 1000, 0, 3700, 0, '2026-03-01', $u->id);
        $this->glRow($equity, 'SAR', 0, 3700, 0, 3700, '2026-03-01', $u->id); // base-currency contra (not revalued)

        // Closing rate at period end = 3.75 → revalued base 3,750 → +50 gain.
        ExchangeRate::query()->create(['currency_id' => $usd->id, 'rate_date' => '2026-03-31', 'rate' => 3.75, 'created_by' => $u->id]);
    }

    private function glRow(int $accountId, string $cur, float $debit, float $credit, float $baseDebit, float $baseCredit, string $date, int $uid): void
    {
        GlTransaction::query()->create([
            'batch_number' => 'GLB-'.str_replace('-', '', $date).'-000001', 'fiscal_period_id' => $this->periodId,
            'transaction_date' => $date, 'account_id' => $accountId, 'debit' => $debit, 'credit' => $credit,
            'currency_code' => $cur, 'exchange_rate' => 3.70, 'base_debit' => $baseDebit, 'base_credit' => $baseCredit,
            'source_type' => 'test', 'source_id' => 1, 'description' => 'fx', 'is_reversal' => false, 'posted_by' => $uid, 'posted_at' => now(),
        ]);
    }

    private function actAs(string $role): void
    {
        $u = User::factory()->create();
        $u->roles()->attach(Role::query()->where('name', $role)->firstOrFail());
        Sanctum::actingAs($u->fresh());
    }

    public function test_preview_computes_the_unrealized_adjustment(): void
    {
        $this->actAs('finance-manager');

        $data = $this->postJson('/api/v1/general-ledger/fx-revaluation/preview', ['as_of' => '2026-03-31'])->assertOk()->json('data');

        $bankRow = collect($data['rows'])->firstWhere('account_code', '1011');
        $this->assertEqualsWithDelta(1000, $bankRow['foreign_balance'], 0.01);
        $this->assertEqualsWithDelta(3700, $bankRow['current_base'], 0.01);
        $this->assertEqualsWithDelta(3750, $bankRow['revalued_base'], 0.01);
        $this->assertEqualsWithDelta(50, $bankRow['adjustment'], 0.01);
    }

    public function test_post_writes_a_balanced_revaluation_entry(): void
    {
        $this->actAs('finance-manager');

        $this->postJson('/api/v1/general-ledger/fx-revaluation/post', ['as_of' => '2026-03-31', 'fx_account_id' => $this->fxGainLossId])
            ->assertCreated();

        $batch = \App\Models\FxRevaluation::query()->latest('id')->value('batch_number');
        $rows = GlTransaction::query()->where('batch_number', $batch)->get();

        $this->assertEqualsWithDelta($rows->sum('base_debit'), $rows->sum('base_credit'), 0.01);
        // USD bank revalued up by 50 (debit); FX gain credited 50.
        $this->assertEqualsWithDelta(50, $rows->firstWhere('account_id', $this->bankId)->base_debit, 0.01);
        $this->assertEqualsWithDelta(50, $rows->firstWhere('account_id', $this->fxGainLossId)->base_credit, 0.01);
    }
}
