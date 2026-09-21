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
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * E7 — Treasury cash position & weekly cash-flow forecast.
 */
final class TreasuryForecastTest extends TestCase
{
    use RefreshDatabase;

    private int $bankId;
    private int $periodId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RbacSeeder::class);

        $this->bankId = ChartOfAccount::query()->create(['code' => '1001', 'name' => 'Main bank', 'account_type' => 'asset', 'normal_balance' => 'debit', 'level' => 1, 'is_postable' => true, 'is_bank_account' => true, 'status' => 'active'])->id;

        $year = FiscalYear::query()->create(['code' => 'FY2026', 'name' => 'FY2026', 'start_date' => '2026-01-01', 'end_date' => '2026-12-31', 'status' => 'open']);
        $this->periodId = FiscalPeriod::query()->create(['fiscal_year_id' => $year->id, 'period_number' => 6, 'name' => 'Jun 2026', 'start_date' => '2026-06-01', 'end_date' => '2026-06-30', 'status' => 'open'])->id;

        $u = User::factory()->create();
        // Opening cash position 100,000.
        GlTransaction::query()->create([
            'batch_number' => 'GLB-OPEN-000001', 'fiscal_period_id' => $this->periodId, 'transaction_date' => '2026-06-01',
            'account_id' => $this->bankId, 'debit' => 100000, 'credit' => 0, 'currency_code' => 'SAR', 'exchange_rate' => 1,
            'base_debit' => 100000, 'base_credit' => 0, 'source_type' => 'test', 'source_id' => 1, 'description' => 'open',
            'is_reversal' => false, 'posted_by' => $u->id, 'posted_at' => now(),
        ]);
    }

    private function actAs(string $role): void
    {
        $u = User::factory()->create();
        $u->roles()->attach(Role::query()->where('name', $role)->firstOrFail());
        Sanctum::actingAs($u->fresh());
    }

    public function test_cash_position_sums_bank_account_balances(): void
    {
        $this->actAs('finance-manager');

        $data = $this->getJson('/api/v1/banking/treasury/position')->assertOk()->json('data');

        $this->assertEqualsWithDelta(100000, $data['total'], 0.01);
        $this->assertSame('1001', $data['accounts'][0]['code']);
    }

    public function test_forecast_layers_treasury_items_onto_the_running_balance(): void
    {
        $this->actAs('finance-manager');

        // A planned inflow next week.
        $nextWeek = Carbon::now()->startOfWeek()->addWeek()->addDay()->toDateString();
        $this->postJson('/api/v1/banking/treasury/items', [
            'description' => 'Loan drawdown', 'direction' => 'in', 'amount' => 25000, 'expected_date' => $nextWeek,
        ])->assertCreated();

        $data = $this->getJson('/api/v1/banking/treasury/forecast?weeks=4')->assertOk()->json('data');

        $this->assertEqualsWithDelta(100000, $data['opening'], 0.01);
        $this->assertCount(4, $data['weeks']);
        // Week 2 carries the +25,000 inflow.
        $this->assertEqualsWithDelta(25000, $data['weeks'][1]['treasury_in'], 0.01);
        $this->assertEqualsWithDelta(125000, $data['weeks'][1]['closing'], 0.01);
        // And it persists into the final running balance.
        $this->assertEqualsWithDelta(125000, $data['weeks'][3]['closing'], 0.01);
    }
}
