<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\ChartOfAccount;
use App\Models\CostCenter;
use App\Models\Currency;
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
 * F26 — Allocation journals: split a cost across cost centres by percentage.
 */
final class AllocationTest extends TestCase
{
    use RefreshDatabase;

    private int $source;
    private int $target;
    private int $ccA;
    private int $ccB;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RbacSeeder::class);

        Currency::query()->create(['code' => 'SAR', 'name' => 'Saudi Riyal', 'symbol' => 'SR', 'decimal_places' => 2, 'is_base' => true, 'status' => 'active']);

        $this->source = ChartOfAccount::query()->create(['code' => '5800', 'name' => 'Shared overhead', 'account_type' => 'expense', 'normal_balance' => 'debit', 'level' => 1, 'is_postable' => true, 'status' => 'active'])->id;
        $this->target = ChartOfAccount::query()->create(['code' => '5801', 'name' => 'Allocated overhead', 'account_type' => 'expense', 'normal_balance' => 'debit', 'level' => 1, 'is_postable' => true, 'status' => 'active'])->id;
        $this->ccA = CostCenter::query()->create(['code' => 'CC-A', 'name' => 'Broking', 'status' => 'active'])->id;
        $this->ccB = CostCenter::query()->create(['code' => 'CC-B', 'name' => 'Admin', 'status' => 'active'])->id;

        $year = FiscalYear::query()->create(['code' => 'FY2026', 'name' => 'FY2026', 'start_date' => '2026-01-01', 'end_date' => '2026-12-31', 'status' => 'open']);
        FiscalPeriod::query()->create(['fiscal_year_id' => $year->id, 'period_number' => 3, 'name' => 'Mar 2026', 'start_date' => '2026-03-01', 'end_date' => '2026-03-31', 'status' => 'open']);
    }

    private function actAs(string $role): void
    {
        $u = User::factory()->create();
        $u->roles()->attach(Role::query()->where('name', $role)->firstOrFail());
        Sanctum::actingAs($u->fresh());
    }

    private function rulePayload(): array
    {
        return [
            'name' => 'Overhead split', 'source_account_id' => $this->source, 'target_account_id' => $this->target,
            'lines' => [
                ['cost_center_id' => $this->ccA, 'percentage' => 60],
                ['cost_center_id' => $this->ccB, 'percentage' => 40],
            ],
        ];
    }

    public function test_shares_must_total_100(): void
    {
        $this->actAs('accountant');
        $bad = $this->rulePayload();
        $bad['lines'][1]['percentage'] = 30; // 60 + 30 = 90

        $this->postJson('/api/v1/general-ledger/allocations', $bad)->assertStatus(422);
    }

    public function test_create_and_run_posts_a_balanced_split(): void
    {
        $this->actAs('accountant');
        $rule = $this->postJson('/api/v1/general-ledger/allocations', $this->rulePayload())->assertCreated()->json('data');

        $this->actAs('finance-manager');
        $this->postJson("/api/v1/general-ledger/allocations/{$rule['id']}/run", ['amount' => 1000, 'date' => '2026-03-15'])
            ->assertCreated();

        $batch = GlTransaction::query()->latest('id')->value('batch_number');
        $rows = GlTransaction::query()->where('batch_number', $batch)->get();

        // Cr source 1000; Dr target@A 600; Dr target@B 400.
        $this->assertEqualsWithDelta(1000, $rows->sum('base_debit'), 0.01);
        $this->assertEqualsWithDelta(1000, $rows->sum('base_credit'), 0.01);
        $this->assertEqualsWithDelta(1000, $rows->firstWhere('account_id', $this->source)->base_credit, 0.01);
        $this->assertEqualsWithDelta(600, $rows->where('account_id', $this->target)->firstWhere('cost_center_id', $this->ccA)->base_debit, 0.01);
        $this->assertEqualsWithDelta(400, $rows->where('account_id', $this->target)->firstWhere('cost_center_id', $this->ccB)->base_debit, 0.01);
    }
}
