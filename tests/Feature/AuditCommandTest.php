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
 * G1 — Audit Command Center overview.
 */
final class AuditCommandTest extends TestCase
{
    use RefreshDatabase;

    private int $periodId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RbacSeeder::class);

        $year = FiscalYear::query()->create(['code' => 'FY2026', 'name' => 'FY2026', 'start_date' => '2026-01-01', 'end_date' => '2026-12-31', 'status' => 'open']);
        $this->periodId = FiscalPeriod::query()->create(['fiscal_year_id' => $year->id, 'period_number' => 1, 'name' => 'Jan', 'start_date' => '2026-01-01', 'end_date' => '2026-01-31', 'status' => 'open'])->id;
    }

    private function actAs(string $role): void
    {
        $u = User::factory()->create();
        $u->roles()->attach(Role::query()->where('name', $role)->firstOrFail());
        Sanctum::actingAs($u->fresh());
    }

    private function glRow(int $accountId, float $debit, float $credit): void
    {
        GlTransaction::query()->create([
            'batch_number' => 'GLB-1', 'fiscal_period_id' => $this->periodId, 'transaction_date' => '2026-01-10',
            'account_id' => $accountId, 'debit' => $debit, 'credit' => $credit, 'currency_code' => 'SAR', 'exchange_rate' => 1,
            'base_debit' => $debit, 'base_credit' => $credit, 'source_type' => 'test', 'source_id' => 1, 'description' => 'x',
            'is_reversal' => false, 'posted_by' => User::factory()->create()->id, 'posted_at' => now(),
        ]);
    }

    public function test_overview_reports_balanced_ledger_and_full_readiness(): void
    {
        $this->actAs('finance-manager');

        $a = ChartOfAccount::query()->create(['code' => '1001', 'name' => 'Cash', 'account_type' => 'asset', 'normal_balance' => 'debit', 'level' => 1, 'is_postable' => true, 'status' => 'active'])->id;
        $b = ChartOfAccount::query()->create(['code' => '4001', 'name' => 'Revenue', 'account_type' => 'revenue', 'normal_balance' => 'credit', 'level' => 1, 'is_postable' => true, 'status' => 'active'])->id;
        $this->glRow($a, 1000, 0);
        $this->glRow($b, 0, 1000);

        $data = $this->getJson('/api/v1/compliance/audit-command')->assertOk()->json('data');

        $this->assertEqualsWithDelta(0, $data['controls']['gl_imbalance'], 0.01);
        $this->assertSame(1, $data['controls']['open_periods']);
        // Balanced + open period + no risks + no drafts → strong readiness.
        $glCheck = collect($data['readiness'])->firstWhere('check', 'General ledger balanced');
        $this->assertTrue($glCheck['ok']);
        $this->assertGreaterThanOrEqual(80, $data['readiness_score']);
    }

    public function test_overview_flags_an_unbalanced_ledger_as_a_critical_exception(): void
    {
        $this->actAs('finance-manager');

        $a = ChartOfAccount::query()->create(['code' => '1001', 'name' => 'Cash', 'account_type' => 'asset', 'normal_balance' => 'debit', 'level' => 1, 'is_postable' => true, 'status' => 'active'])->id;
        $this->glRow($a, 1000, 0);   // one-sided → imbalance

        $data = $this->getJson('/api/v1/compliance/audit-command')->assertOk()->json('data');

        $this->assertEqualsWithDelta(1000, $data['controls']['gl_imbalance'], 0.01);
        $this->assertContains('gl_imbalance', collect($data['exceptions'])->pluck('type')->all());
        $glCheck = collect($data['readiness'])->firstWhere('check', 'General ledger balanced');
        $this->assertFalse($glCheck['ok']);
    }
}
