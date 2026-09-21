<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\ChartOfAccount;
use App\Models\Currency;
use App\Models\Role;
use App\Models\User;
use App\Models\Vendor;
use App\Models\VendorInvoice;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * E11 — Vendor self-service portal (consolidated financial view).
 */
final class VendorProfileTest extends TestCase
{
    use RefreshDatabase;

    private Vendor $vendor;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RbacSeeder::class);
        Currency::query()->create(['code' => 'SAR', 'name' => 'Saudi Riyal', 'symbol' => 'SR', 'decimal_places' => 2, 'is_base' => true, 'status' => 'active']);

        $ap = ChartOfAccount::query()->create(['code' => '2105', 'name' => 'AP', 'account_type' => 'liability', 'normal_balance' => 'credit', 'level' => 1, 'is_postable' => true, 'status' => 'active'])->id;
        $u = User::factory()->create();
        $this->vendor = Vendor::query()->create([
            'vendor_code' => 'VEND-1', 'name' => 'Microteck', 'vendor_type' => 'supplier', 'status' => 'active',
            'currency_code' => 'SAR', 'payment_terms_days' => 30, 'default_payable_account_id' => $ap, 'created_by' => $u->id,
        ]);

        VendorInvoice::query()->create([
            'invoice_number' => 'VINV-1', 'vendor_id' => $this->vendor->id, 'invoice_date' => now()->toDateString(), 'due_date' => now()->toDateString(),
            'currency_code' => 'SAR', 'exchange_rate' => 1, 'subtotal' => 3000, 'tax_amount' => 0, 'total_amount' => 3000, 'amount_paid' => 1000,
            'status' => 'posted', 'created_by' => $u->id, 'posted_by' => $u->id, 'posted_at' => now(),
        ]);
        // A draft bill should not count toward outstanding.
        VendorInvoice::query()->create([
            'invoice_number' => 'VINV-2', 'vendor_id' => $this->vendor->id, 'invoice_date' => now()->toDateString(),
            'currency_code' => 'SAR', 'exchange_rate' => 1, 'subtotal' => 9999, 'tax_amount' => 0, 'total_amount' => 9999, 'amount_paid' => 0,
            'status' => 'draft', 'created_by' => $u->id,
        ]);
    }

    private function actAs(string $role): void
    {
        $u = User::factory()->create();
        $u->roles()->attach(Role::query()->where('name', $role)->firstOrFail());
        Sanctum::actingAs($u->fresh());
    }

    public function test_profile_aggregates_outstanding_aging_and_open_count(): void
    {
        $this->actAs('accountant');

        $data = $this->getJson("/api/v1/accounts-payable/vendors/{$this->vendor->id}/profile")->assertOk()->json('data');

        $this->assertSame('Microteck', $data['vendor']['name']);
        $this->assertEqualsWithDelta(2000, $data['kpis']['outstanding'], 0.01);   // 3000 − 1000, draft excluded
        $this->assertSame(1, $data['kpis']['open_invoices_count']);
        $this->assertSame(2, $data['kpis']['invoices_count']);
        $this->assertEqualsWithDelta(2000, $data['aging']['current'], 0.01);      // due today → current
    }
}
