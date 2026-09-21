<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\ChartOfAccount;
use App\Models\Currency;
use App\Models\Role;
use App\Models\User;
use App\Models\Vendor;
use App\Models\VendorInvoice;
use App\Models\VendorPayment;
use App\Models\VendorPaymentAllocation;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * P3.17 — AP aging report and the per-vendor statement (running balance).
 */
final class AccountsPayableReportTest extends TestCase
{
    use RefreshDatabase;

    private int $vendorId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RbacSeeder::class);
        $this->buildFixture();
    }

    private function buildFixture(): void
    {
        Currency::query()->create(['code' => 'SAR', 'name' => 'Saudi Riyal', 'symbol' => 'SR', 'decimal_places' => 2, 'is_base' => true, 'status' => 'active']);
        $payable = ChartOfAccount::query()->create(['code' => '2218', 'name' => 'Accounts payable', 'account_type' => 'liability', 'normal_balance' => 'credit', 'level' => 1, 'is_postable' => true, 'status' => 'active']);

        $creator = User::factory()->create();
        $vendor = Vendor::query()->create([
            'vendor_code' => 'VEND-2026-000001', 'name' => 'Acme Trading', 'vendor_type' => 'supplier',
            'status' => 'active', 'currency_code' => 'SAR', 'default_payable_account_id' => $payable->id,
            'payment_terms_days' => 30, 'created_by' => $creator->id,
        ]);
        $this->vendorId = $vendor->id;

        // Current (due in the future): 1,000 owed.
        VendorInvoice::query()->create([
            'invoice_number' => 'VINV-2026-000001', 'vendor_invoice_no' => 'SUP-1', 'vendor_id' => $vendor->id,
            'invoice_date' => '2026-06-01', 'due_date' => '2026-12-31', 'currency_code' => 'SAR', 'exchange_rate' => 1,
            'subtotal' => 1000, 'tax_amount' => 0, 'total_amount' => 1000, 'amount_paid' => 0,
            'status' => 'posted', 'created_by' => $creator->id,
        ]);

        // Long overdue (due 2026-01-01): 1,150 total, 150 paid → 1,000 outstanding, 120+ bucket.
        $overdue = VendorInvoice::query()->create([
            'invoice_number' => 'VINV-2026-000002', 'vendor_invoice_no' => 'SUP-2', 'vendor_id' => $vendor->id,
            'invoice_date' => '2026-01-01', 'due_date' => '2026-01-01', 'currency_code' => 'SAR', 'exchange_rate' => 1,
            'subtotal' => 1000, 'tax_amount' => 150, 'total_amount' => 1150, 'amount_paid' => 150,
            'status' => 'posted', 'created_by' => $creator->id,
        ]);

        $payment = VendorPayment::query()->create([
            'payment_number' => 'PMT-2026-000001', 'vendor_id' => $vendor->id, 'payment_date' => '2026-02-15',
            'bank_account_id' => $payable->id, 'payment_method' => 'bank_transfer', 'currency_code' => 'SAR',
            'exchange_rate' => 1, 'amount' => 150, 'status' => 'posted', 'created_by' => $creator->id,
        ]);
        VendorPaymentAllocation::query()->create([
            'vendor_payment_id' => $payment->id, 'vendor_invoice_id' => $overdue->id, 'amount' => 150,
        ]);

        // A draft invoice must NOT appear in either report.
        VendorInvoice::query()->create([
            'invoice_number' => 'VINV-2026-000003', 'vendor_invoice_no' => 'SUP-3', 'vendor_id' => $vendor->id,
            'invoice_date' => '2026-05-01', 'due_date' => '2026-05-01', 'currency_code' => 'SAR', 'exchange_rate' => 1,
            'subtotal' => 500, 'tax_amount' => 0, 'total_amount' => 500, 'amount_paid' => 0,
            'status' => 'draft', 'created_by' => $creator->id,
        ]);
    }

    private function actingAsRole(string $role): void
    {
        $user = User::factory()->create();
        $user->roles()->attach(Role::query()->where('name', $role)->firstOrFail());
        Sanctum::actingAs($user->fresh());
    }

    public function test_aging_buckets_outstanding_by_due_date(): void
    {
        $this->actingAsRole('accountant');

        $res = $this->getJson('/api/v1/accounts-payable/reports/aging?as_of=2026-06-15')
            ->assertOk()
            ->assertJsonPath('data.rows.0.vendor_code', 'VEND-2026-000001');

        $this->assertEqualsWithDelta(2000, $res->json('data.totals.total'), 0.01);

        // 1,000 current + 1,000 in the 120+ bucket; nothing in between.
        $this->assertEqualsWithDelta(1000, $res->json('data.totals.current'), 0.01);
        $this->assertEqualsWithDelta(1000, $res->json('data.totals.120_plus'), 0.01);
        $this->assertEqualsWithDelta(0, $res->json('data.totals.1_30'), 0.01);
    }

    public function test_vendor_ledger_runs_a_balance(): void
    {
        $this->actingAsRole('accountant');

        $res = $this->getJson("/api/v1/accounts-payable/reports/vendor-ledger/{$this->vendorId}?to=2026-06-15")
            ->assertOk()
            ->assertJsonPath('data.opening_balance', 0)
            ->assertJsonPath('data.closing_balance', 2000);

        // 2 posted invoices + 1 posted payment; the draft is excluded.
        $this->assertCount(3, $res->json('data.entries'));
    }

    public function test_report_requires_view_permission(): void
    {
        Sanctum::actingAs(User::factory()->create()); // no roles → no permissions
        $this->getJson('/api/v1/accounts-payable/reports/aging')->assertForbidden();
    }
}
