<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\ChartOfAccount;
use App\Models\CostCenter;
use App\Models\Currency;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * E10 — Fixed asset transfers & maintenance log.
 */
final class AssetRegisterTest extends TestCase
{
    use RefreshDatabase;

    private int $assetAcc;
    private int $accumAcc;
    private int $expenseAcc;
    private int $ccA;
    private int $ccB;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RbacSeeder::class);

        Currency::query()->create(['code' => 'SAR', 'name' => 'Saudi Riyal', 'symbol' => 'SR', 'decimal_places' => 2, 'is_base' => true, 'status' => 'active']);

        $this->assetAcc = ChartOfAccount::query()->create(['code' => '130101', 'name' => 'Equipment', 'account_type' => 'asset', 'normal_balance' => 'debit', 'level' => 1, 'is_postable' => true, 'status' => 'active'])->id;
        $this->accumAcc = ChartOfAccount::query()->create(['code' => '130901', 'name' => 'Accum dep', 'account_type' => 'asset', 'normal_balance' => 'credit', 'level' => 1, 'is_postable' => true, 'status' => 'active'])->id;
        $this->expenseAcc = ChartOfAccount::query()->create(['code' => '520101', 'name' => 'Depreciation', 'account_type' => 'expense', 'normal_balance' => 'debit', 'level' => 1, 'is_postable' => true, 'status' => 'active'])->id;

        $this->ccA = CostCenter::query()->create(['code' => 'CC-A', 'name' => 'Head office', 'status' => 'active'])->id;
        $this->ccB = CostCenter::query()->create(['code' => 'CC-B', 'name' => 'Branch', 'status' => 'active'])->id;
    }

    private function actAs(string $role): void
    {
        $u = User::factory()->create();
        $u->roles()->attach(Role::query()->where('name', $role)->firstOrFail());
        Sanctum::actingAs($u->fresh());
    }

    private function newAsset(): int
    {
        $this->actAs('accountant');

        return $this->postJson('/api/v1/fixed-assets', [
            'name' => 'Laptop', 'acquisition_date' => '2026-01-01', 'cost' => 6000, 'salvage_value' => 0,
            'useful_life_months' => 36, 'asset_account_id' => $this->assetAcc,
            'accum_depreciation_account_id' => $this->accumAcc, 'depreciation_expense_account_id' => $this->expenseAcc,
            'cost_center_id' => $this->ccA,
        ])->assertCreated()->json('data.id');
    }

    public function test_transfer_moves_the_asset_and_logs_from_to(): void
    {
        $id = $this->newAsset();
        $this->actAs('finance-manager');

        $this->postJson("/api/v1/fixed-assets/{$id}/transfer", [
            'transfer_date' => '2026-03-01', 'to_cost_center_id' => $this->ccB,
            'to_location' => 'Jeddah office', 'to_custodian' => 'A. Khan', 'reason' => 'Staff relocation',
        ])->assertCreated();

        $this->assertDatabaseHas('fixed_assets', ['id' => $id, 'cost_center_id' => $this->ccB, 'location' => 'Jeddah office', 'custodian' => 'A. Khan']);
        $this->assertDatabaseHas('asset_transfers', ['fixed_asset_id' => $id, 'from_cost_center_id' => $this->ccA, 'to_cost_center_id' => $this->ccB, 'to_location' => 'Jeddah office']);
    }

    public function test_maintenance_is_logged_and_totalled_in_history(): void
    {
        $id = $this->newAsset();
        $this->actAs('finance-manager');

        $this->postJson("/api/v1/fixed-assets/{$id}/maintenance", [
            'maintenance_date' => '2026-04-10', 'type' => 'corrective', 'description' => 'Screen replacement', 'cost' => 450, 'vendor' => 'TechFix',
        ])->assertCreated();
        $this->postJson("/api/v1/fixed-assets/{$id}/maintenance", [
            'maintenance_date' => '2026-05-12', 'type' => 'preventive', 'description' => 'Service', 'cost' => 150,
        ])->assertCreated();

        $data = $this->getJson("/api/v1/fixed-assets/{$id}/register-history")->assertOk()->json('data');

        $this->assertCount(2, $data['maintenance']);
        $this->assertEqualsWithDelta(600, (float) $data['maintenance_total'], 0.01);
    }

    public function test_no_op_transfer_is_rejected(): void
    {
        $id = $this->newAsset();
        $this->actAs('finance-manager');

        // Same cost centre, no location/custodian change → nothing to transfer.
        $this->postJson("/api/v1/fixed-assets/{$id}/transfer", [
            'transfer_date' => '2026-03-01', 'to_cost_center_id' => $this->ccA, 'reason' => 'no change',
        ])->assertStatus(422);
    }
}
