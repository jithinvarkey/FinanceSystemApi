<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\ChartOfAccount;
use App\Models\Currency;
use App\Models\FiscalPeriod;
use App\Models\FiscalYear;
use App\Models\GlTransaction;
use App\Models\RevenueSchedule;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * E6 — Revenue recognition (IFRS 15).
 */
final class RevenueRecognitionTest extends TestCase
{
    use RefreshDatabase;

    private int $deferredId;
    private int $revenueId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RbacSeeder::class);

        Currency::query()->create(['code' => 'SAR', 'name' => 'Saudi Riyal', 'symbol' => 'SR', 'decimal_places' => 2, 'is_base' => true, 'status' => 'active']);

        $this->deferredId = ChartOfAccount::query()->create(['code' => '2401', 'name' => 'Deferred revenue', 'account_type' => 'liability', 'normal_balance' => 'credit', 'level' => 1, 'is_postable' => true, 'status' => 'active'])->id;
        $this->revenueId = ChartOfAccount::query()->create(['code' => '4001', 'name' => 'Commission revenue', 'account_type' => 'revenue', 'normal_balance' => 'credit', 'level' => 1, 'is_postable' => true, 'status' => 'active'])->id;

        $year = FiscalYear::query()->create(['code' => 'FY2026', 'name' => 'FY2026', 'start_date' => '2026-01-01', 'end_date' => '2026-12-31', 'status' => 'open']);
        foreach (range(1, 6) as $m) {
            FiscalPeriod::query()->create([
                'fiscal_year_id' => $year->id, 'period_number' => $m, 'name' => 'M'.$m,
                'start_date' => sprintf('2026-%02d-01', $m), 'end_date' => date('Y-m-t', strtotime(sprintf('2026-%02d-01', $m))), 'status' => 'open',
            ]);
        }
    }

    private function actAs(string $role): void
    {
        $u = User::factory()->create();
        $u->roles()->attach(Role::query()->where('name', $role)->firstOrFail());
        Sanctum::actingAs($u->fresh());
    }

    public function test_creating_a_schedule_defers_revenue_and_splits_into_monthly_lines(): void
    {
        $this->actAs('finance-manager');

        $schedule = $this->postJson('/api/v1/general-ledger/revenue-schedules', [
            'name' => 'Annual policy commission',
            'total_amount' => 1200,
            'deferred_account_id' => $this->deferredId,
            'revenue_account_id' => $this->revenueId,
            'start_date' => '2026-01-15',
            'end_date' => '2026-04-30',
        ])->assertCreated()->json('data');

        // Jan, Feb, Mar, Apr = 4 monthly lines.
        $this->assertCount(4, $schedule['lines']);
        $this->assertEqualsWithDelta(1200, collect($schedule['lines'])->sum(fn ($l) => (float) $l['amount']), 0.01);

        // Deferral posting: Dr revenue 1200 / Cr deferred 1200.
        $deferral = GlTransaction::query()->where('source_type', RevenueSchedule::class)->get();
        $this->assertEqualsWithDelta(1200, $deferral->firstWhere('account_id', $this->deferredId)->base_credit, 0.01);
        $this->assertEqualsWithDelta(1200, $deferral->firstWhere('account_id', $this->revenueId)->base_debit, 0.01);
    }

    public function test_recognize_earns_due_lines_and_completes_when_fully_recognized(): void
    {
        $this->actAs('finance-manager');

        $this->postJson('/api/v1/general-ledger/revenue-schedules', [
            'name' => 'Quarterly fee', 'total_amount' => 300,
            'deferred_account_id' => $this->deferredId, 'revenue_account_id' => $this->revenueId,
            'start_date' => '2026-01-01', 'end_date' => '2026-03-31',
        ])->assertCreated();

        // Recognise through Feb: Jan + Feb lines (100 + 100 = 200).
        $r1 = $this->postJson('/api/v1/general-ledger/revenue-schedules/recognize', ['as_of' => '2026-02-28'])->assertOk()->json('data');
        $this->assertSame(2, $r1['recognized']);
        $this->assertEqualsWithDelta(200, (float) $r1['amount'], 0.01);

        $schedule = RevenueSchedule::query()->latest('id')->first();
        $this->assertEqualsWithDelta(200, (float) $schedule->recognized_amount, 0.01);
        $this->assertSame('active', $schedule->status);

        // Recognise through Mar: final line earned → completed.
        $r2 = $this->postJson('/api/v1/general-ledger/revenue-schedules/recognize', ['as_of' => '2026-03-31'])->assertOk()->json('data');
        $this->assertSame(1, $r2['recognized']);

        $schedule->refresh();
        $this->assertEqualsWithDelta(300, (float) $schedule->recognized_amount, 0.01);
        $this->assertSame('completed', $schedule->status);

        // Net effect on revenue account = 0 deferred (1200... 300) then earned back; deferred liability nets to 0.
        $deferredBalance = GlTransaction::query()->where('account_id', $this->deferredId)->sum('base_credit')
            - GlTransaction::query()->where('account_id', $this->deferredId)->sum('base_debit');
        $this->assertEqualsWithDelta(0, $deferredBalance, 0.01);
    }
}
