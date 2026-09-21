<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\ChartOfAccount;
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
 * N3 — KPI engine + role scorecards.
 */
final class KpiScorecardTest extends TestCase
{
    use RefreshDatabase;

    private int $periodId;
    private int $bankId;
    private int $revId;
    private int $expId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RbacSeeder::class);
        // kpi_targets is seeded by its migration (inline insert).

        $this->bankId = ChartOfAccount::query()->create(['code' => '1001', 'name' => 'Bank', 'account_type' => 'asset', 'normal_balance' => 'debit', 'level' => 1, 'is_postable' => true, 'is_bank_account' => true, 'status' => 'active'])->id;
        $this->revId = ChartOfAccount::query()->create(['code' => '4001', 'name' => 'Revenue', 'account_type' => 'revenue', 'normal_balance' => 'credit', 'level' => 1, 'is_postable' => true, 'status' => 'active'])->id;
        $this->expId = ChartOfAccount::query()->create(['code' => '5001', 'name' => 'Expense', 'account_type' => 'expense', 'normal_balance' => 'debit', 'level' => 1, 'is_postable' => true, 'status' => 'active'])->id;

        $year = FiscalYear::query()->create(['code' => 'FY'.now()->year, 'name' => 'FY', 'start_date' => now()->startOfYear()->toDateString(), 'end_date' => now()->endOfYear()->toDateString(), 'status' => 'open']);
        $this->periodId = FiscalPeriod::query()->create(['fiscal_year_id' => $year->id, 'period_number' => (int) now()->format('n'), 'name' => 'M', 'start_date' => now()->startOfMonth()->toDateString(), 'end_date' => now()->endOfMonth()->toDateString(), 'status' => 'open'])->id;

        $u = User::factory()->create();
        // Revenue 10,000 (credit), Expense 6,000 (debit), Bank 20,000 (debit).
        $this->row($this->revId, 0, 10000, $u->id);
        $this->row($this->expId, 6000, 0, $u->id);
        $this->row($this->bankId, 20000, 0, $u->id);
    }

    private function row(int $accountId, float $debit, float $credit, int $uid): void
    {
        GlTransaction::query()->create([
            'batch_number' => 'GLB-'.$accountId, 'fiscal_period_id' => $this->periodId, 'transaction_date' => now()->toDateString(),
            'account_id' => $accountId, 'debit' => $debit, 'credit' => $credit, 'currency_code' => 'SAR', 'exchange_rate' => 1,
            'base_debit' => $debit, 'base_credit' => $credit, 'source_type' => 'test', 'source_id' => 1, 'description' => 'x',
            'is_reversal' => false, 'posted_by' => $uid, 'posted_at' => now(),
        ]);
    }

    private function actAs(string $role): void
    {
        $u = User::factory()->create();
        $u->roles()->attach(Role::query()->where('name', $role)->firstOrFail());
        Sanctum::actingAs($u->fresh());
    }

    public function test_library_computes_values_and_status_vs_target(): void
    {
        $this->actAs('finance-manager');

        $lib = collect($this->getJson('/api/v1/general-ledger/kpis/library')->assertOk()->json('data'));

        $net = $lib->firstWhere('kpi_key', 'net_profit_ytd');
        $this->assertEqualsWithDelta(4000, $net['value'], 0.01);          // 10,000 − 6,000

        $margin = $lib->firstWhere('kpi_key', 'gross_margin_pct');
        $this->assertEqualsWithDelta(40, $margin['value'], 0.01);         // 4,000 / 10,000
        $this->assertSame('on_track', $margin['status']);                 // 40% ≥ 20% target

        $cash = $lib->firstWhere('kpi_key', 'cash_position');
        $this->assertEqualsWithDelta(20000, $cash['value'], 0.01);
    }

    public function test_scorecard_filters_kpis_by_audience(): void
    {
        $this->actAs('finance-manager');

        $board = collect($this->getJson('/api/v1/general-ledger/kpis/scorecard?audience=board')->assertOk()->json('data.kpis'));
        $keys = $board->pluck('kpi_key');

        $this->assertTrue($keys->contains('net_profit_ytd'));            // board KPI
        $this->assertFalse($keys->contains('ap_outstanding'));           // CFO-only KPI
    }
}
