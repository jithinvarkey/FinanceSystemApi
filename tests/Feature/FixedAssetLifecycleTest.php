<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\AssetStatus;
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
use Illuminate\Support\Carbon;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * F23/F24/F25 — Fixed-asset lifecycle: reducing-balance depreciation, disposal,
 * impairment, revaluation and CWIP capitalisation.
 */
final class FixedAssetLifecycleTest extends TestCase
{
    use RefreshDatabase;

    private int $assetAcc;
    private int $accumAcc;
    private int $expenseAcc;
    private int $cashAcc;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RbacSeeder::class);

        Currency::query()->create(['code' => 'SAR', 'name' => 'Saudi Riyal', 'symbol' => 'SR', 'decimal_places' => 2, 'is_base' => true, 'status' => 'active']);

        $this->assetAcc = ChartOfAccount::query()->create(['code' => '130101', 'name' => 'Equipment', 'account_type' => 'asset', 'normal_balance' => 'debit', 'level' => 1, 'is_postable' => true, 'status' => 'active'])->id;
        $this->accumAcc = ChartOfAccount::query()->create(['code' => '130901', 'name' => 'Accum dep', 'account_type' => 'asset', 'normal_balance' => 'credit', 'level' => 1, 'is_postable' => true, 'status' => 'active'])->id;
        $this->expenseAcc = ChartOfAccount::query()->create(['code' => '520101', 'name' => 'Depreciation', 'account_type' => 'expense', 'normal_balance' => 'debit', 'level' => 1, 'is_postable' => true, 'status' => 'active'])->id;
        $this->cashAcc = ChartOfAccount::query()->create(['code' => '1001', 'name' => 'Cash', 'account_type' => 'asset', 'normal_balance' => 'debit', 'level' => 1, 'is_postable' => true, 'status' => 'active'])->id;

        $year = FiscalYear::query()->create(['code' => 'FY2026', 'name' => 'FY2026', 'start_date' => '2026-01-01', 'end_date' => '2026-12-31', 'status' => 'open']);
        foreach ([1, 6] as $m) {
            FiscalPeriod::query()->create(['fiscal_year_id' => $year->id, 'period_number' => $m, 'name' => "M{$m} 2026", 'start_date' => "2026-0{$m}-01", 'end_date' => Carbon::parse('2026-0'.$m.'-01')->endOfMonth()->toDateString(), 'status' => 'open']);
        }
    }

    private function actAs(string $role): void
    {
        $u = User::factory()->create();
        $u->roles()->attach(Role::query()->where('name', $role)->firstOrFail());
        Sanctum::actingAs($u->fresh());
    }

    private function newAsset(array $overrides = []): int
    {
        $this->actAs('accountant');

        return $this->postJson('/api/v1/fixed-assets', array_merge([
            'name' => 'Machine', 'acquisition_date' => '2026-01-01', 'cost' => 12000, 'salvage_value' => 0,
            'useful_life_months' => 24, 'asset_account_id' => $this->assetAcc,
            'accum_depreciation_account_id' => $this->accumAcc, 'depreciation_expense_account_id' => $this->expenseAcc,
        ], $overrides))->assertCreated()->json('data.id');
    }

    public function test_reducing_balance_charges_on_book_value(): void
    {
        // 12,000 cost, 24 months → rate 2/24 = 8.333%. First month ≈ 1,000.
        $id = $this->newAsset(['depreciation_method' => 'reducing_balance']);

        $this->actAs('finance-manager');
        $this->postJson('/api/v1/fixed-assets/run-depreciation', ['as_of' => '2026-01-31'])
            ->assertOk()->assertJsonPath('data.total', '1000.00');
    }

    public function test_dispose_at_a_gain_writes_a_balanced_entry(): void
    {
        $id = $this->newAsset();
        // Depreciate one month (straight-line 500) → book value 11,500.
        $this->actAs('finance-manager');
        $this->postJson('/api/v1/fixed-assets/run-depreciation', ['as_of' => '2026-01-31'])->assertOk();

        $gainLoss = ChartOfAccount::query()->create(['code' => '4901', 'name' => 'Gain on disposal', 'account_type' => 'revenue', 'normal_balance' => 'credit', 'level' => 1, 'is_postable' => true, 'status' => 'active'])->id;

        // Sell for 12,000 → gain 500.
        $this->postJson("/api/v1/fixed-assets/{$id}/dispose", [
            'proceeds' => 12000, 'cash_account_id' => $this->cashAcc, 'gain_loss_account_id' => $gainLoss, 'disposal_date' => '2026-01-31',
        ])->assertOk()->assertJsonPath('data.status', 'disposed');

        $disposal = \App\Models\AssetDisposal::query()->latest('id')->first();
        $rows = GlTransaction::query()->where('batch_number', $disposal->batch_number)->get();
        $this->assertEqualsWithDelta($rows->sum('base_debit'), $rows->sum('base_credit'), 0.01);
        $this->assertEqualsWithDelta(500, (float) $disposal->gain_loss, 0.01);
        $this->assertEqualsWithDelta(500, (float) $rows->firstWhere('account_id', $gainLoss)->base_credit, 0.01);
    }

    public function test_impairment_writes_down_carrying_value(): void
    {
        $id = $this->newAsset();
        $impair = ChartOfAccount::query()->create(['code' => '5902', 'name' => 'Impairment loss', 'account_type' => 'expense', 'normal_balance' => 'debit', 'level' => 1, 'is_postable' => true, 'status' => 'active'])->id;

        $this->actAs('finance-manager');
        $this->postJson("/api/v1/fixed-assets/{$id}/impair", ['amount' => 2000, 'impairment_account_id' => $impair, 'date' => '2026-06-30'])
            ->assertOk()->assertJsonPath('data.book_value', '10000.00');
    }

    public function test_cwip_does_not_depreciate_until_capitalised(): void
    {
        $id = $this->newAsset(['is_cwip' => true]);

        $this->actAs('finance-manager');
        // CWIP is skipped.
        $this->postJson('/api/v1/fixed-assets/run-depreciation', ['as_of' => '2026-01-31'])->assertOk()->assertJsonPath('data.assets', 0);

        // Capitalise, then it depreciates.
        $this->postJson("/api/v1/fixed-assets/{$id}/capitalize", ['in_service_date' => '2026-01-01'])->assertOk()->assertJsonPath('data.is_cwip', false);
        $this->postJson('/api/v1/fixed-assets/run-depreciation', ['as_of' => '2026-01-31'])->assertOk()->assertJsonPath('data.assets', 1);
    }
}
