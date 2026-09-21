<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\ChartOfAccount;
use App\Models\CostCenter;
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
 * N5 — Profit-center P&L + contract register.
 */
final class ProfitCenterAndContractTest extends TestCase
{
    use RefreshDatabase;

    private int $periodId;
    private int $revId;
    private int $expId;
    private int $ccA;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RbacSeeder::class);

        $this->revId = ChartOfAccount::query()->create(['code' => '4001', 'name' => 'Revenue', 'account_type' => 'revenue', 'normal_balance' => 'credit', 'level' => 1, 'is_postable' => true, 'status' => 'active'])->id;
        $this->expId = ChartOfAccount::query()->create(['code' => '5001', 'name' => 'Expense', 'account_type' => 'expense', 'normal_balance' => 'debit', 'level' => 1, 'is_postable' => true, 'status' => 'active'])->id;
        $this->ccA = CostCenter::query()->create(['code' => 'CC-BRK', 'name' => 'Broking', 'status' => 'active'])->id;

        $year = FiscalYear::query()->create(['code' => 'FY2026', 'name' => 'FY2026', 'start_date' => '2026-01-01', 'end_date' => '2026-12-31', 'status' => 'open']);
        $this->periodId = FiscalPeriod::query()->create(['fiscal_year_id' => $year->id, 'period_number' => 1, 'name' => 'M', 'start_date' => '2026-01-01', 'end_date' => '2026-03-31', 'status' => 'open'])->id;

        $u = User::factory()->create();
        // Broking cost centre: revenue 5,000 (credit), expense 2,000 (debit) → profit 3,000.
        $this->row($this->revId, $this->ccA, 0, 5000, $u->id);
        $this->row($this->expId, $this->ccA, 2000, 0, $u->id);
    }

    private function row(int $accountId, ?int $ccId, float $debit, float $credit, int $uid): void
    {
        GlTransaction::query()->create([
            'batch_number' => 'GLB-'.$accountId, 'fiscal_period_id' => $this->periodId, 'transaction_date' => '2026-02-10',
            'account_id' => $accountId, 'cost_center_id' => $ccId, 'debit' => $debit, 'credit' => $credit, 'currency_code' => 'SAR', 'exchange_rate' => 1,
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

    public function test_profit_center_statement_computes_profit_per_cost_center(): void
    {
        $this->actAs('finance-manager');

        $data = $this->getJson('/api/v1/general-ledger/profit-centers/statement')->assertOk()->json('data');

        $broking = collect($data['rows'])->firstWhere('cost_center', 'Broking');
        $this->assertEqualsWithDelta(5000, $broking['revenue'], 0.01);
        $this->assertEqualsWithDelta(2000, $broking['expense'], 0.01);
        $this->assertEqualsWithDelta(3000, $broking['profit'], 0.01);
        $this->assertEqualsWithDelta(60, $broking['margin_pct'], 0.01);     // 3,000 / 5,000
        $this->assertEqualsWithDelta(3000, $data['totals']['profit'], 0.01);
    }

    public function test_contract_can_be_registered_and_appears_in_expiring(): void
    {
        $this->actAs('it-supervisor');

        $contract = $this->postJson('/api/v1/contracts', [
            'title' => 'Tawuniya commission agreement', 'party_type' => 'insurer', 'party_name' => 'Tawuniya',
            'category' => 'commission', 'start_date' => '2026-01-01', 'end_date' => now()->addDays(20)->toDateString(), 'value' => 250000,
        ])->assertCreated()->json('data');

        $this->assertStringStartsWith('CON-', $contract['contract_number']);
        $this->assertSame('active', $contract['status']);

        // Expiring within 60 days includes it (ends in 20 days).
        $expiring = $this->getJson('/api/v1/contracts/expiring?days=60')->assertOk()->json('data');
        $this->assertCount(1, $expiring);
        $this->assertSame('Tawuniya commission agreement', $expiring[0]['title']);

        // A contract ending far out is not flagged.
        $this->postJson('/api/v1/contracts', [
            'title' => 'Long service deal', 'party_type' => 'vendor', 'category' => 'service',
            'start_date' => '2026-01-01', 'end_date' => now()->addDays(300)->toDateString(),
        ])->assertCreated();
        $this->assertCount(1, $this->getJson('/api/v1/contracts/expiring?days=60')->assertOk()->json('data'));
    }
}
