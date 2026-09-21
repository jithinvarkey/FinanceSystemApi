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
 * N2 — Procurement-to-Pay: PO → goods receipt → 3-way match.
 */
final class ProcurementTest extends TestCase
{
    use RefreshDatabase;

    private int $vendorId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RbacSeeder::class);
        Currency::query()->create(['code' => 'SAR', 'name' => 'Saudi Riyal', 'symbol' => 'SR', 'decimal_places' => 2, 'is_base' => true, 'status' => 'active']);

        $u = User::factory()->create();
        $ap = ChartOfAccount::query()->create(['code' => '2105', 'name' => 'AP', 'account_type' => 'liability', 'normal_balance' => 'credit', 'level' => 1, 'is_postable' => true, 'status' => 'active'])->id;
        $this->vendorId = Vendor::query()->create(['vendor_code' => 'VEND-1', 'name' => 'Microteck', 'vendor_type' => 'supplier', 'status' => 'active', 'currency_code' => 'SAR', 'payment_terms_days' => 30, 'default_payable_account_id' => $ap, 'created_by' => $u->id])->id;
    }

    private function actAs(string $role): void
    {
        $u = User::factory()->create();
        $u->roles()->attach(Role::query()->where('name', $role)->firstOrFail());
        Sanctum::actingAs($u->fresh());
    }

    private function createPo(): array
    {
        return $this->postJson('/api/v1/accounts-payable/purchase-orders', [
            'vendor_id' => $this->vendorId, 'order_date' => '2026-03-01',
            'lines' => [
                ['description' => 'Laptops', 'quantity' => 10, 'unit_price' => 500],
                ['description' => 'Monitors', 'quantity' => 10, 'unit_price' => 200],
            ],
        ])->assertCreated()->json('data');
    }

    public function test_po_totals_lines_and_requires_approval_before_receipt(): void
    {
        $this->actAs('accountant');
        $po = $this->createPo();

        $this->assertEqualsWithDelta(7000, (float) $po['total_amount'], 0.01);   // 10×500 + 10×200
        $this->assertSame('draft', $po['status']);

        // Cannot receive a draft PO.
        $this->postJson("/api/v1/accounts-payable/purchase-orders/{$po['id']}/receive", [
            'receipt_date' => '2026-03-05', 'lines' => [['purchase_order_line_id' => $po['lines'][0]['id'], 'quantity' => 5]],
        ])->assertStatus(422);

        $this->postJson("/api/v1/accounts-payable/purchase-orders/{$po['id']}/approve")->assertOk()->assertJsonPath('data.status', 'approved');
    }

    public function test_partial_then_full_receipt_updates_status_and_three_way_match(): void
    {
        $this->actAs('accountant');
        $po = $this->createPo();
        $this->postJson("/api/v1/accounts-payable/purchase-orders/{$po['id']}/approve")->assertOk();

        $lap = $po['lines'][0]['id'];
        $mon = $po['lines'][1]['id'];

        // Receive 5 laptops only → partially_received.
        $this->postJson("/api/v1/accounts-payable/purchase-orders/{$po['id']}/receive", [
            'receipt_date' => '2026-03-05', 'lines' => [['purchase_order_line_id' => $lap, 'quantity' => 5]],
        ])->assertCreated();

        $show = $this->getJson("/api/v1/accounts-payable/purchase-orders/{$po['id']}")->assertOk()->json('data');
        $this->assertSame('partially_received', $show['order']['status']);
        $this->assertEqualsWithDelta(2500, $show['match']['received'], 0.01);    // 5×500
        $this->assertFalse($show['match']['fully_received']);

        // Over-receipt rejected (only 5 laptops left).
        $this->postJson("/api/v1/accounts-payable/purchase-orders/{$po['id']}/receive", [
            'receipt_date' => '2026-03-06', 'lines' => [['purchase_order_line_id' => $lap, 'quantity' => 6]],
        ])->assertStatus(422);

        // Receive the rest → received.
        $this->postJson("/api/v1/accounts-payable/purchase-orders/{$po['id']}/receive", [
            'receipt_date' => '2026-03-07', 'lines' => [['purchase_order_line_id' => $lap, 'quantity' => 5], ['purchase_order_line_id' => $mon, 'quantity' => 10]],
        ])->assertCreated();

        // Link a vendor invoice of 7000 → fully matched.
        $u = User::factory()->create();
        VendorInvoice::query()->create([
            'invoice_number' => 'VINV-1', 'vendor_id' => $this->vendorId, 'purchase_order_id' => $po['id'],
            'invoice_date' => '2026-03-08', 'due_date' => '2026-04-08', 'currency_code' => 'SAR', 'exchange_rate' => 1,
            'subtotal' => 7000, 'tax_amount' => 0, 'total_amount' => 7000, 'amount_paid' => 0, 'status' => 'posted',
            'created_by' => $u->id, 'posted_by' => $u->id, 'posted_at' => now(),
        ]);

        $show = $this->getJson("/api/v1/accounts-payable/purchase-orders/{$po['id']}")->assertOk()->json('data');
        $this->assertSame('received', $show['order']['status']);
        $this->assertEqualsWithDelta(7000, $show['match']['received'], 0.01);
        $this->assertEqualsWithDelta(7000, $show['match']['invoiced'], 0.01);
        $this->assertTrue($show['match']['matched']);
        $this->assertFalse($show['match']['over_invoiced']);
    }
}
