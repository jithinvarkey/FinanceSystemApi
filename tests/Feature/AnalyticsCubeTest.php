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
 * E8 — BI analytics cube + what-if.
 */
final class AnalyticsCubeTest extends TestCase
{
    use RefreshDatabase;

    private int $revId;
    private int $periodId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RbacSeeder::class);

        $this->revId = ChartOfAccount::query()->create(['code' => '4001', 'name' => 'Revenue', 'account_type' => 'revenue', 'normal_balance' => 'credit', 'level' => 1, 'is_postable' => true, 'status' => 'active'])->id;
        $expId = ChartOfAccount::query()->create(['code' => '5001', 'name' => 'Expense', 'account_type' => 'expense', 'normal_balance' => 'debit', 'level' => 1, 'is_postable' => true, 'status' => 'active'])->id;

        $year = FiscalYear::query()->create(['code' => 'FY2026', 'name' => 'FY2026', 'start_date' => '2026-01-01', 'end_date' => '2026-12-31', 'status' => 'open']);
        $this->periodId = FiscalPeriod::query()->create(['fiscal_year_id' => $year->id, 'period_number' => 1, 'name' => 'M', 'start_date' => '2026-01-01', 'end_date' => '2026-03-31', 'status' => 'open'])->id;

        $u = User::factory()->create();
        // Revenue: Jan 1000 (credit), Feb 1500 (credit). Expense: Jan 400 (debit).
        $this->row($this->revId, '2026-01-10', 0, 1000, $u->id);
        $this->row($this->revId, '2026-02-10', 0, 1500, $u->id);
        $this->row($expId, '2026-01-15', 400, 0, $u->id);
    }

    private function row(int $accountId, string $date, float $debit, float $credit, int $uid): void
    {
        GlTransaction::query()->create([
            'batch_number' => 'GLB-'.str_replace('-', '', $date).'-000001', 'fiscal_period_id' => $this->periodId,
            'transaction_date' => $date, 'account_id' => $accountId, 'debit' => $debit, 'credit' => $credit,
            'currency_code' => 'SAR', 'exchange_rate' => 1, 'base_debit' => $debit, 'base_credit' => $credit,
            'source_type' => 'test', 'source_id' => 1, 'description' => 'x', 'is_reversal' => false, 'posted_by' => $uid, 'posted_at' => now(),
        ]);
    }

    private function actAs(string $role): void
    {
        $u = User::factory()->create();
        $u->roles()->attach(Role::query()->where('name', $role)->firstOrFail());
        Sanctum::actingAs($u->fresh());
    }

    public function test_cube_pivots_net_by_month_and_account_type(): void
    {
        $this->actAs('finance-manager');

        $data = $this->getJson('/api/v1/general-ledger/analytics/cube?measure=net&row=month&col=account_type')->assertOk()->json('data');

        $this->assertSame(['2026-01', '2026-02'], $data['rows']);
        $this->assertSame(['expense', 'revenue'], $data['columns']);
        // Jan revenue net = -1000 (credit), Jan expense net = +400.
        $this->assertEqualsWithDelta(-1000, $data['cells']['2026-01']['revenue'], 0.01);
        $this->assertEqualsWithDelta(400, $data['cells']['2026-01']['expense'], 0.01);
        $this->assertEqualsWithDelta(-1500, $data['cells']['2026-02']['revenue'], 0.01);
        $this->assertEqualsWithDelta(-2100, $data['grand_total'], 0.01);
    }

    public function test_what_if_projects_growth_from_recent_actuals(): void
    {
        $this->actAs('finance-manager');

        $data = $this->postJson('/api/v1/general-ledger/analytics/what-if', [
            'account_type' => 'revenue', 'growth_pct' => 10, 'adjustment' => 0, 'months' => 2,
        ])->assertOk()->json('data');

        // Revenue net actuals: Jan -1000, Feb -1500.
        $this->assertCount(2, $data['actuals']);
        // Base = avg(-1000,-1500) = -1250; month+1 = -1250 * 1.10 = -1375.
        $this->assertCount(2, $data['projection']);
        $this->assertEqualsWithDelta(-1375, $data['projection'][0]['value'], 0.01);
        $this->assertSame('2026-03', $data['projection'][0]['month']);
    }
}
