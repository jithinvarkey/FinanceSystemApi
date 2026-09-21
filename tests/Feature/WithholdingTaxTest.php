<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\ChartOfAccount;
use App\Models\Currency;
use App\Models\FiscalPeriod;
use App\Models\FiscalYear;
use App\Models\GlTransaction;
use App\Models\Role;
use App\Models\User;
use App\Models\Vendor;
use App\Models\VendorInvoice;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * F13 — withholding tax: Dr AP / Cr WHT payable on the net base, reducing the bill.
 */
final class WithholdingTaxTest extends TestCase
{
    use RefreshDatabase;

    private int $apId;
    private int $whtId;
    private int $invoiceId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RbacSeeder::class);

        Currency::query()->create(['code' => 'SAR', 'name' => 'Saudi Riyal', 'symbol' => 'SR', 'decimal_places' => 2, 'is_base' => true, 'status' => 'active']);
        $this->apId = ChartOfAccount::query()->create(['code' => '220101', 'name' => 'AP', 'account_type' => 'liability', 'normal_balance' => 'credit', 'level' => 1, 'is_postable' => true, 'status' => 'active'])->id;
        $this->whtId = ChartOfAccount::query()->create(['code' => '220501', 'name' => 'WHT payable', 'account_type' => 'liability', 'normal_balance' => 'credit', 'level' => 1, 'is_postable' => true, 'status' => 'active'])->id;

        $year = FiscalYear::query()->create(['code' => 'FY2026', 'name' => 'FY2026', 'start_date' => '2026-01-01', 'end_date' => '2026-12-31', 'status' => 'open']);
        FiscalPeriod::query()->create(['fiscal_year_id' => $year->id, 'period_number' => 1, 'name' => 'Now', 'start_date' => now()->startOfMonth()->toDateString(), 'end_date' => now()->endOfMonth()->toDateString(), 'status' => 'open']);

        $u = User::factory()->create();
        $vendor = Vendor::query()->create(['vendor_code' => 'V1', 'name' => 'NonResident Co', 'vendor_type' => 'service_provider', 'status' => 'active', 'currency_code' => 'SAR', 'default_payable_account_id' => $this->apId, 'wht_rate' => 5, 'created_by' => $u->id]);
        $this->invoiceId = VendorInvoice::query()->create([
            'invoice_number' => 'VINV-1', 'vendor_id' => $vendor->id, 'invoice_date' => now()->toDateString(), 'currency_code' => 'SAR', 'exchange_rate' => 1,
            'subtotal' => 2000, 'tax_amount' => 300, 'total_amount' => 2300, 'amount_paid' => 0, 'status' => 'posted', 'created_by' => $u->id, 'posted_by' => $u->id, 'posted_at' => now(),
        ])->id;
    }

    public function test_withholding_posts_and_reports(): void
    {
        $user = User::factory()->create();
        $user->roles()->attach(Role::query()->where('name', 'finance-manager')->firstOrFail());
        Sanctum::actingAs($user->fresh());

        $this->postJson("/api/v1/accounts-payable/vendor-invoices/{$this->invoiceId}/withhold", ['wht_payable_account_id' => $this->whtId])->assertOk();

        // 5% × 2,000 base = 100 WHT: Dr AP 100 / Cr WHT payable 100.
        $rows = GlTransaction::query()->where('source_type', VendorInvoice::class)->where('source_id', $this->invoiceId)->get();
        $this->assertEqualsWithDelta(100, (float) $rows->firstWhere('account_id', $this->apId)->base_debit, 0.01);
        $this->assertEqualsWithDelta(100, (float) $rows->firstWhere('account_id', $this->whtId)->base_credit, 0.01);
        $this->assertEqualsWithDelta(2200, VendorInvoice::query()->find($this->invoiceId)->balanceDue(), 0.01);

        // WHT report.
        $report = $this->getJson('/api/v1/accounts-payable/reports/withholding')->assertOk()->json('data');
        $this->assertSame('100.00', $report['total']);
        $this->assertCount(1, $report['rows']);
    }
}
