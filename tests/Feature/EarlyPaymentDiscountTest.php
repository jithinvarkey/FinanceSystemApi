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
 * F12 — early-payment discount: Dr AP / Cr discount income, reduces the bill balance.
 */
final class EarlyPaymentDiscountTest extends TestCase
{
    use RefreshDatabase;

    private int $apId;
    private int $discountIncomeId;
    private int $invoiceId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RbacSeeder::class);

        Currency::query()->create(['code' => 'SAR', 'name' => 'Saudi Riyal', 'symbol' => 'SR', 'decimal_places' => 2, 'is_base' => true, 'status' => 'active']);
        $this->apId = ChartOfAccount::query()->create(['code' => '220101', 'name' => 'AP', 'account_type' => 'liability', 'normal_balance' => 'credit', 'level' => 1, 'is_postable' => true, 'status' => 'active'])->id;
        $this->discountIncomeId = ChartOfAccount::query()->create(['code' => '410901', 'name' => 'Discount income', 'account_type' => 'revenue', 'normal_balance' => 'credit', 'level' => 1, 'is_postable' => true, 'status' => 'active'])->id;

        $year = FiscalYear::query()->create(['code' => 'FY2026', 'name' => 'FY2026', 'start_date' => '2026-01-01', 'end_date' => '2026-12-31', 'status' => 'open']);
        FiscalPeriod::query()->create(['fiscal_year_id' => $year->id, 'period_number' => 1, 'name' => 'Now', 'start_date' => now()->startOfMonth()->toDateString(), 'end_date' => now()->endOfMonth()->toDateString(), 'status' => 'open']);

        $u = User::factory()->create();
        $vendor = Vendor::query()->create(['vendor_code' => 'V1', 'name' => 'Acme', 'vendor_type' => 'supplier', 'status' => 'active', 'currency_code' => 'SAR', 'default_payable_account_id' => $this->apId, 'settlement_discount_percent' => 2, 'settlement_discount_days' => 10, 'created_by' => $u->id]);
        $this->invoiceId = VendorInvoice::query()->create([
            'invoice_number' => 'VINV-1', 'vendor_id' => $vendor->id, 'invoice_date' => now()->toDateString(), 'currency_code' => 'SAR', 'exchange_rate' => 1,
            'subtotal' => 1000, 'tax_amount' => 0, 'total_amount' => 1000, 'amount_paid' => 0, 'status' => 'posted', 'created_by' => $u->id, 'posted_by' => $u->id, 'posted_at' => now(),
        ])->id;
    }

    public function test_take_discount_posts_and_reduces_balance(): void
    {
        $user = User::factory()->create();
        $user->roles()->attach(Role::query()->where('name', 'finance-manager')->firstOrFail()); // accounts-payable.post
        Sanctum::actingAs($user->fresh());

        $this->postJson("/api/v1/accounts-payable/vendor-invoices/{$this->invoiceId}/take-discount", ['discount_income_account_id' => $this->discountIncomeId])
            ->assertOk();

        // 2% × 1,000 = 20 discount: Dr AP 20 / Cr discount income 20.
        $rows = GlTransaction::query()->where('source_type', VendorInvoice::class)->where('source_id', $this->invoiceId)->get();
        $this->assertEqualsWithDelta(20, (float) $rows->firstWhere('account_id', $this->apId)->base_debit, 0.01);
        $this->assertEqualsWithDelta(20, (float) $rows->firstWhere('account_id', $this->discountIncomeId)->base_credit, 0.01);
        $this->assertEqualsWithDelta(980, VendorInvoice::query()->find($this->invoiceId)->balanceDue(), 0.01);
    }
}
