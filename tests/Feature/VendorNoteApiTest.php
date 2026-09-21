<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\ChartOfAccount;
use App\Models\Currency;
use App\Models\FiscalPeriod;
use App\Models\FiscalYear;
use App\Models\GlTransaction;
use App\Models\Role;
use App\Models\TaxCode;
use App\Models\User;
use App\Models\Vendor;
use App\Models\VendorNote;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * AP credit & debit notes: credit reduces AP (Dr AP / Cr expense+VAT);
 * debit adds AP (Dr expense+VAT / Cr AP).
 */
final class VendorNoteApiTest extends TestCase
{
    use RefreshDatabase;

    private int $apId;
    private int $expenseId;
    private int $vatInputId;
    private int $vat15Id;
    private int $vendorId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RbacSeeder::class);

        Currency::query()->create(['code' => 'SAR', 'name' => 'Saudi Riyal', 'symbol' => 'SR', 'decimal_places' => 2, 'is_base' => true, 'status' => 'active']);
        $this->apId = ChartOfAccount::query()->create(['code' => '220101', 'name' => 'AP', 'account_type' => 'liability', 'normal_balance' => 'credit', 'level' => 1, 'is_postable' => true, 'status' => 'active'])->id;
        $this->expenseId = ChartOfAccount::query()->create(['code' => '510101', 'name' => 'Supplies', 'account_type' => 'expense', 'normal_balance' => 'debit', 'level' => 1, 'is_postable' => true, 'status' => 'active'])->id;
        $this->vatInputId = ChartOfAccount::query()->create(['code' => '120701', 'name' => 'Input VAT', 'account_type' => 'asset', 'normal_balance' => 'debit', 'level' => 1, 'is_postable' => true, 'status' => 'active'])->id;
        $this->vat15Id = TaxCode::query()->create(['code' => 'VAT15', 'name' => 'VAT 15%', 'tax_type' => 'both', 'rate' => 15.0, 'input_account_id' => $this->vatInputId, 'is_recoverable' => true, 'status' => 'active'])->id;

        $year = FiscalYear::query()->create(['code' => 'FY2026', 'name' => 'FY2026', 'start_date' => '2026-01-01', 'end_date' => '2026-12-31', 'status' => 'open']);
        FiscalPeriod::query()->create(['fiscal_year_id' => $year->id, 'period_number' => 6, 'name' => 'Jun 2026', 'start_date' => '2026-06-01', 'end_date' => '2026-06-30', 'status' => 'open']);

        $u = User::factory()->create();
        $this->vendorId = Vendor::query()->create(['vendor_code' => 'VEND-1', 'name' => 'Acme', 'vendor_type' => 'supplier', 'status' => 'active', 'currency_code' => 'SAR', 'default_payable_account_id' => $this->apId, 'created_by' => $u->id])->id;
    }

    private function actingAsRole(string $role): void
    {
        $user = User::factory()->create();
        $user->roles()->attach(Role::query()->where('name', $role)->firstOrFail());
        Sanctum::actingAs($user->fresh());
    }

    private function newNote(string $type): int
    {
        return $this->postJson('/api/v1/accounts-payable/vendor-notes', [
            'note_type' => $type, 'vendor_id' => $this->vendorId, 'note_date' => '2026-06-10',
            'lines' => [['account_id' => $this->expenseId, 'amount' => 1000, 'tax_code_id' => $this->vat15Id]],
        ])->assertCreated()->json('data.id');
    }

    public function test_credit_note_reduces_ap(): void
    {
        $this->actingAsRole('accountant');
        $id = $this->newNote('credit');
        VendorNote::query()->whereKey($id)->update(['status' => 'approved']);

        $this->actingAsRole('finance-manager'); // accounts-payable.post
        $this->postJson("/api/v1/accounts-payable/vendor-notes/{$id}/post")->assertOk()->assertJsonPath('data.status', 'posted');

        $rows = GlTransaction::query()->where('source_type', VendorNote::class)->where('source_id', $id)->get();
        // Dr AP 1150 / Cr expense 1000 + Cr VAT 150.
        $this->assertEqualsWithDelta(1150, (float) $rows->firstWhere('account_id', $this->apId)->base_debit, 0.01);
        $this->assertEqualsWithDelta(1000, (float) $rows->firstWhere('account_id', $this->expenseId)->base_credit, 0.01);
        $this->assertEqualsWithDelta(150, (float) $rows->firstWhere('account_id', $this->vatInputId)->base_credit, 0.01);
    }

    public function test_debit_note_adds_ap(): void
    {
        $this->actingAsRole('accountant');
        $id = $this->newNote('debit');
        VendorNote::query()->whereKey($id)->update(['status' => 'approved']);

        $this->actingAsRole('finance-manager');
        $this->postJson("/api/v1/accounts-payable/vendor-notes/{$id}/post")->assertOk();

        $rows = GlTransaction::query()->where('source_type', VendorNote::class)->where('source_id', $id)->get();
        // Dr expense 1000 + Dr VAT 150 / Cr AP 1150.
        $this->assertEqualsWithDelta(1000, (float) $rows->firstWhere('account_id', $this->expenseId)->base_debit, 0.01);
        $this->assertEqualsWithDelta(1150, (float) $rows->firstWhere('account_id', $this->apId)->base_credit, 0.01);
    }
}
