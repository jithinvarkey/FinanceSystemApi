<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\ChartOfAccount;
use App\Models\Currency;
use App\Models\FiscalPeriod;
use App\Models\FiscalYear;
use App\Models\Role;
use App\Models\User;
use App\Models\Vendor;
use App\Models\VendorInvoice;
use App\Models\VendorPayment;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * AP batch payment run: one draft payment per vendor across selected invoices.
 */
final class PaymentRunApiTest extends TestCase
{
    use RefreshDatabase;

    private int $bankId;
    private array $invoiceIds = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RbacSeeder::class);

        Currency::query()->create(['code' => 'SAR', 'name' => 'Saudi Riyal', 'symbol' => 'SR', 'decimal_places' => 2, 'is_base' => true, 'status' => 'active']);
        $ap = ChartOfAccount::query()->create(['code' => '220101', 'name' => 'AP', 'account_type' => 'liability', 'normal_balance' => 'credit', 'level' => 1, 'is_postable' => true, 'status' => 'active'])->id;
        $this->bankId = ChartOfAccount::query()->create(['code' => '110101', 'name' => 'Bank', 'account_type' => 'asset', 'normal_balance' => 'debit', 'level' => 1, 'is_postable' => true, 'is_bank_account' => true, 'status' => 'active'])->id;

        $year = FiscalYear::query()->create(['code' => 'FY2026', 'name' => 'FY2026', 'start_date' => '2026-01-01', 'end_date' => '2026-12-31', 'status' => 'open']);
        FiscalPeriod::query()->create(['fiscal_year_id' => $year->id, 'period_number' => 1, 'name' => 'Now', 'start_date' => now()->startOfMonth()->toDateString(), 'end_date' => now()->endOfMonth()->toDateString(), 'status' => 'open']);

        $u = User::factory()->create();
        $v1 = Vendor::query()->create(['vendor_code' => 'V1', 'name' => 'Acme', 'vendor_type' => 'supplier', 'status' => 'active', 'currency_code' => 'SAR', 'default_payable_account_id' => $ap, 'created_by' => $u->id]);
        $v2 = Vendor::query()->create(['vendor_code' => 'V2', 'name' => 'Beta', 'vendor_type' => 'supplier', 'status' => 'active', 'currency_code' => 'SAR', 'default_payable_account_id' => $ap, 'created_by' => $u->id]);

        // Two invoices for v1, one for v2 ⇒ a run should make 2 payments.
        foreach ([[$v1->id, 'VINV-1', 500], [$v1->id, 'VINV-2', 300], [$v2->id, 'VINV-3', 700]] as [$vid, $no, $amt]) {
            $this->invoiceIds[] = VendorInvoice::query()->create([
                'invoice_number' => $no, 'vendor_id' => $vid, 'invoice_date' => '2026-06-01', 'due_date' => '2026-06-30', 'currency_code' => 'SAR', 'exchange_rate' => 1,
                'subtotal' => $amt, 'tax_amount' => 0, 'total_amount' => $amt, 'amount_paid' => 0, 'status' => 'posted', 'created_by' => $u->id, 'posted_by' => $u->id, 'posted_at' => now(),
            ])->id;
        }
    }

    public function test_payment_run_creates_one_payment_per_vendor(): void
    {
        $user = User::factory()->create();
        $user->roles()->attach(Role::query()->where('name', 'accountant')->firstOrFail()); // accounts-payable.manage
        Sanctum::actingAs($user->fresh());

        $this->getJson('/api/v1/accounts-payable/outstanding-invoices')->assertOk()->assertJsonCount(3, 'data');

        $result = $this->postJson('/api/v1/accounts-payable/payment-runs', [
            'invoice_ids' => $this->invoiceIds, 'bank_account_id' => $this->bankId, 'payment_date' => '2026-06-25',
        ])->assertOk()->json('data');

        $this->assertCount(2, $result); // one payment per vendor
        $this->assertSame(2, VendorPayment::query()->count());
        // Acme's payment covers both invoices = 800.
        $acme = collect($result)->firstWhere('vendor', 'Acme');
        $this->assertSame('800.00', $acme['amount']);
    }
}
