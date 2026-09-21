<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\AssetDepreciationEntry;
use App\Models\ChartOfAccount;
use App\Models\Currency;
use App\Models\FiscalPeriod;
use App\Models\FiscalYear;
use App\Models\FixedAsset;
use App\Models\GlTransaction;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * P7 — Fixed assets: straight-line depreciation posts Dr expense / Cr accumulated.
 */
final class FixedAssetApiTest extends TestCase
{
    use RefreshDatabase;

    private int $assetAcc;
    private int $accumAcc;
    private int $expenseAcc;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RbacSeeder::class);

        Currency::query()->create(['code' => 'SAR', 'name' => 'Saudi Riyal', 'symbol' => 'SR', 'decimal_places' => 2, 'is_base' => true, 'status' => 'active']);
        $this->assetAcc = ChartOfAccount::query()->create(['code' => '130101', 'name' => 'Equipment', 'account_type' => 'asset', 'normal_balance' => 'debit', 'level' => 1, 'is_postable' => true, 'status' => 'active'])->id;
        $this->accumAcc = ChartOfAccount::query()->create(['code' => '130901', 'name' => 'Accum dep', 'account_type' => 'asset', 'normal_balance' => 'credit', 'level' => 1, 'is_postable' => true, 'status' => 'active'])->id;
        $this->expenseAcc = ChartOfAccount::query()->create(['code' => '520101', 'name' => 'Depreciation expense', 'account_type' => 'expense', 'normal_balance' => 'debit', 'level' => 1, 'is_postable' => true, 'status' => 'active'])->id;

        $year = FiscalYear::query()->create(['code' => 'FY2026', 'name' => 'FY2026', 'start_date' => '2026-01-01', 'end_date' => '2026-12-31', 'status' => 'open']);
        FiscalPeriod::query()->create(['fiscal_year_id' => $year->id, 'period_number' => 6, 'name' => 'Jun 2026', 'start_date' => '2026-06-01', 'end_date' => '2026-06-30', 'status' => 'open']);
    }

    private function actingAsRole(string $role): void
    {
        $user = User::factory()->create();
        $user->roles()->attach(Role::query()->where('name', $role)->firstOrFail());
        Sanctum::actingAs($user->fresh());
    }

    private function newAsset(): int
    {
        return $this->postJson('/api/v1/fixed-assets', [
            'name' => 'Laptop fleet',
            'acquisition_date' => '2026-01-01',
            'cost' => 12000,
            'salvage_value' => 0,
            'useful_life_months' => 24, // 500/month
            'asset_account_id' => $this->assetAcc,
            'accum_depreciation_account_id' => $this->accumAcc,
            'depreciation_expense_account_id' => $this->expenseAcc,
        ])->assertCreated()->json('data.id');
    }

    public function test_register_computes_monthly_depreciation(): void
    {
        $this->actingAsRole('accountant');

        $this->postJson('/api/v1/fixed-assets', [
            'name' => 'Laptop', 'acquisition_date' => '2026-01-01', 'cost' => 12000, 'salvage_value' => 0,
            'useful_life_months' => 24, 'asset_account_id' => $this->assetAcc,
            'accum_depreciation_account_id' => $this->accumAcc, 'depreciation_expense_account_id' => $this->expenseAcc,
        ])->assertCreated()->assertJsonPath('data.monthly_depreciation', '500.00')->assertJsonPath('data.book_value', '12000.00');
    }

    public function test_run_depreciation_posts_and_is_idempotent(): void
    {
        $this->actingAsRole('accountant');
        $id = $this->newAsset();

        $this->actingAsRole('finance-manager'); // general-ledger.post
        $this->postJson('/api/v1/fixed-assets/run-depreciation', ['as_of' => '2026-06-15'])
            ->assertOk()->assertJsonPath('data.assets', 1)->assertJsonPath('data.total', '500.00');

        $rows = GlTransaction::query()->where('source_type', AssetDepreciationEntry::class)->get();
        $this->assertCount(2, $rows);
        $this->assertEqualsWithDelta(500, (float) $rows->firstWhere('account_id', $this->expenseAcc)->base_debit, 0.01);
        $this->assertEqualsWithDelta(500, (float) $rows->firstWhere('account_id', $this->accumAcc)->base_credit, 0.01);
        $this->assertEqualsWithDelta(500, (float) FixedAsset::query()->find($id)->accumulated_depreciation, 0.01);

        // Re-running the same month does nothing.
        $this->postJson('/api/v1/fixed-assets/run-depreciation', ['as_of' => '2026-06-28'])
            ->assertOk()->assertJsonPath('data.assets', 0);
        $this->assertCount(2, GlTransaction::query()->where('source_type', AssetDepreciationEntry::class)->get());
    }

    public function test_run_requires_post_permission(): void
    {
        $this->actingAsRole('accountant');
        $this->newAsset();

        $this->postJson('/api/v1/fixed-assets/run-depreciation', ['as_of' => '2026-06-15'])->assertStatus(403);
    }
}
