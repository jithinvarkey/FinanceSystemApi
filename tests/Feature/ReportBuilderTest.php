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
 * N4 — Self-service report builder.
 */
final class ReportBuilderTest extends TestCase
{
    use RefreshDatabase;

    private int $periodId;
    private int $revId;
    private int $expId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RbacSeeder::class);

        $this->revId = ChartOfAccount::query()->create(['code' => '4001', 'name' => 'Revenue', 'account_type' => 'revenue', 'normal_balance' => 'credit', 'level' => 1, 'is_postable' => true, 'status' => 'active'])->id;
        $this->expId = ChartOfAccount::query()->create(['code' => '5001', 'name' => 'Expense', 'account_type' => 'expense', 'normal_balance' => 'debit', 'level' => 1, 'is_postable' => true, 'status' => 'active'])->id;

        $year = FiscalYear::query()->create(['code' => 'FY2026', 'name' => 'FY2026', 'start_date' => '2026-01-01', 'end_date' => '2026-12-31', 'status' => 'open']);
        $this->periodId = FiscalPeriod::query()->create(['fiscal_year_id' => $year->id, 'period_number' => 1, 'name' => 'M', 'start_date' => '2026-01-01', 'end_date' => '2026-03-31', 'status' => 'open'])->id;

        $u = User::factory()->create();
        $this->row($this->revId, '2026-01-10', 0, 1000, $u->id);
        $this->row($this->revId, '2026-02-10', 0, 1500, $u->id);
        $this->row($this->expId, '2026-01-15', 400, 0, $u->id);
    }

    private function row(int $accountId, string $date, float $debit, float $credit, int $uid): void
    {
        GlTransaction::query()->create([
            'batch_number' => 'GLB-'.str_replace('-', '', $date), 'fiscal_period_id' => $this->periodId, 'transaction_date' => $date,
            'account_id' => $accountId, 'debit' => $debit, 'credit' => $credit, 'currency_code' => 'SAR', 'exchange_rate' => 1,
            'base_debit' => $debit, 'base_credit' => $credit, 'source_type' => 'test', 'source_id' => 1, 'description' => 'entry',
            'is_reversal' => false, 'posted_by' => $uid, 'posted_at' => now(),
        ]);
    }

    private function actAs(string $role): void
    {
        $u = User::factory()->create();
        $u->roles()->attach(Role::query()->where('name', $role)->firstOrFail());
        Sanctum::actingAs($u->fresh());
    }

    public function test_run_groups_by_dimensions_and_aggregates_measure(): void
    {
        $this->actAs('finance-manager');

        $data = $this->postJson('/api/v1/general-ledger/report-builder/run', [
            'measure' => 'net', 'group_by' => ['account_type', 'month'],
        ])->assertOk()->json('data');

        $this->assertSame(3, $data['row_count']);    // (revenue,2026-01),(revenue,2026-02),(expense,2026-01)
        $rev01 = collect($data['rows'])->firstWhere('label', 'revenue · 2026-01');
        $this->assertEqualsWithDelta(-1000, $rev01['value'], 0.01);
        $this->assertEqualsWithDelta(-2100, $data['grand_total'], 0.01);
    }

    public function test_drill_through_returns_underlying_lines(): void
    {
        $this->actAs('finance-manager');

        $lines = $this->postJson('/api/v1/general-ledger/report-builder/drill', [
            'keys' => ['account_type' => 'revenue', 'month' => '2026-01'],
        ])->assertOk()->json('data');

        $this->assertCount(1, $lines);
        $this->assertEqualsWithDelta(1000, $lines[0]['credit'], 0.01);
    }

    public function test_definitions_can_be_saved_and_listed(): void
    {
        $this->actAs('finance-manager');

        $this->postJson('/api/v1/general-ledger/report-builder/definitions', [
            'name' => 'Net by type & month', 'measure' => 'net', 'group_by' => ['account_type', 'month'],
        ])->assertCreated();

        $list = $this->getJson('/api/v1/general-ledger/report-builder/definitions')->assertOk()->json('data');
        $this->assertCount(1, $list);
        $this->assertSame('account_type,month', $list[0]['group_by']);
    }
}
