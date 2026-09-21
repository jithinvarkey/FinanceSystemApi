<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\ChartOfAccount;
use App\Models\Currency;
use App\Models\FiscalPeriod;
use App\Models\FiscalYear;
use App\Models\GlDimension;
use App\Models\GlTransaction;
use App\Models\JournalEntry;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\ApprovalWorkflowSeeder;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * F27 — Extra GL dimensions: a project/segment tag that flows from a journal
 * line onto the GL and rolls up in the dimension-analysis report.
 */
final class GlDimensionTest extends TestCase
{
    use RefreshDatabase;

    private int $cash;
    private int $revenue;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed([RbacSeeder::class, ApprovalWorkflowSeeder::class]);

        Currency::query()->create(['code' => 'SAR', 'name' => 'Saudi Riyal', 'symbol' => 'SR', 'decimal_places' => 2, 'is_base' => true, 'status' => 'active']);
        $this->cash = ChartOfAccount::query()->create(['code' => '1001', 'name' => 'Cash', 'account_type' => 'asset', 'normal_balance' => 'debit', 'level' => 1, 'is_postable' => true, 'status' => 'active'])->id;
        $this->revenue = ChartOfAccount::query()->create(['code' => '4001', 'name' => 'Revenue', 'account_type' => 'revenue', 'normal_balance' => 'credit', 'level' => 1, 'is_postable' => true, 'status' => 'active'])->id;

        $year = FiscalYear::query()->create(['code' => 'FY2026', 'name' => 'FY2026', 'start_date' => '2026-01-01', 'end_date' => '2026-12-31', 'status' => 'open']);
        FiscalPeriod::query()->create(['fiscal_year_id' => $year->id, 'period_number' => 3, 'name' => 'Mar 2026', 'start_date' => '2026-03-01', 'end_date' => '2026-03-31', 'status' => 'open']);
    }

    private function actAs(string $role): void
    {
        $u = User::factory()->create();
        $u->roles()->attach(Role::query()->where('name', $role)->firstOrFail());
        Sanctum::actingAs($u->fresh());
    }

    public function test_admin_creates_a_dimension(): void
    {
        $this->actAs('it-supervisor');

        $this->postJson('/api/v1/finance-config/dimensions', ['type' => 'project', 'code' => 'PRJ-100', 'name' => 'Riyadh Tower'])
            ->assertCreated()->assertJsonPath('data.code', 'PRJ-100');
    }

    public function test_journal_dimension_flows_to_gl_and_report(): void
    {
        $dim = GlDimension::query()->create(['type' => 'project', 'code' => 'PRJ-1', 'name' => 'Project One', 'status' => 'active']);

        // Maker creates a journal tagging the revenue line with the dimension.
        $this->actAs('accountant');
        $journal = $this->postJson('/api/v1/general-ledger/journals', [
            'journal_date' => '2026-03-10', 'description' => 'Tagged entry',
            'lines' => [
                ['account_id' => $this->cash, 'debit' => 500, 'credit' => 0],
                ['account_id' => $this->revenue, 'debit' => 0, 'credit' => 500, 'dimension_id' => $dim->id],
            ],
        ])->assertCreated()->json('data');

        // Force-approve (the approval workflow is exercised elsewhere) and post.
        JournalEntry::query()->whereKey($journal['id'])->update(['status' => 'approved']);
        $this->actAs('finance-manager');
        $this->postJson("/api/v1/general-ledger/journals/{$journal['id']}/post")->assertOk();

        // The GL revenue row carries the dimension.
        $row = GlTransaction::query()->where('account_id', $this->revenue)->first();
        $this->assertSame($dim->id, (int) $row->dimension_id);

        // The dimension-analysis report rolls it up (revenue credit → net −500).
        $this->actAs('finance-manager');
        $report = $this->getJson('/api/v1/general-ledger/dimension-analysis?from=2026-03-01&to=2026-03-31')->assertOk()->json('data');
        $this->assertEqualsWithDelta(-500, collect($report)->firstWhere('code', 'PRJ-1')['net'], 0.01);
    }
}
