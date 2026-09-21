<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\ApprovalRequest;
use App\Models\ChartOfAccount;
use App\Models\Currency;
use App\Models\FiscalPeriod;
use App\Models\FiscalYear;
use App\Models\GlTransaction;
use App\Models\Role;
use App\Models\User;
use App\Models\Vendor;
use App\Models\VendorInvoice;
use App\Models\VendorPayment;
use Database\Seeders\ApprovalWorkflowSeeder;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * P3.11–P3.15 — Vendor payment: allocate to posted invoices, approve, then
 * post (Dr vendor payable → Cr bank) and settle the invoice.
 */
final class VendorPaymentApiTest extends TestCase
{
    use RefreshDatabase;

    private int $vendorId;
    private int $bankId;
    private int $invoiceId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed([RbacSeeder::class, ApprovalWorkflowSeeder::class]);
        $this->buildFixture();
    }

    private function buildFixture(): void
    {
        Currency::query()->create(['code' => 'SAR', 'name' => 'Saudi Riyal', 'symbol' => 'SR', 'decimal_places' => 2, 'is_base' => true, 'status' => 'active']);

        $payable = ChartOfAccount::query()->create(['code' => '2218', 'name' => 'Accounts payable', 'account_type' => 'liability', 'normal_balance' => 'credit', 'level' => 1, 'is_postable' => true, 'status' => 'active']);
        $bank = ChartOfAccount::query()->create(['code' => '110101', 'name' => 'Bank — current', 'account_type' => 'asset', 'normal_balance' => 'debit', 'level' => 1, 'is_postable' => true, 'is_bank_account' => true, 'status' => 'active']);

        $year = FiscalYear::query()->create(['code' => 'FY2026', 'name' => 'FY2026', 'start_date' => '2026-01-01', 'end_date' => '2026-12-31', 'status' => 'open']);
        FiscalPeriod::query()->create(['fiscal_year_id' => $year->id, 'period_number' => 3, 'name' => 'Mar 2026', 'start_date' => '2026-03-01', 'end_date' => '2026-03-31', 'status' => 'open']);

        $creator = User::factory()->create();
        $vendor = Vendor::query()->create([
            'vendor_code' => 'VEND-2026-000001', 'name' => 'Acme Trading', 'vendor_type' => 'supplier',
            'status' => 'active', 'currency_code' => 'SAR', 'default_payable_account_id' => $payable->id,
            'payment_terms_days' => 30, 'created_by' => $creator->id,
        ]);

        $invoice = VendorInvoice::query()->create([
            'invoice_number' => 'VINV-2026-000001', 'vendor_invoice_no' => 'SUP-1001', 'vendor_id' => $vendor->id,
            'invoice_date' => '2026-03-10', 'currency_code' => 'SAR', 'exchange_rate' => 1,
            'subtotal' => 1000, 'tax_amount' => 150, 'total_amount' => 1150, 'amount_paid' => 0,
            'status' => 'posted', 'created_by' => $creator->id,
        ]);

        $this->vendorId = $vendor->id;
        $this->bankId = $bank->id;
        $this->invoiceId = $invoice->id;
    }

    private function actingAsRole(string $role): User
    {
        $user = User::factory()->create();
        $user->roles()->attach(Role::query()->where('name', $role)->firstOrFail());
        Sanctum::actingAs($user->fresh());

        return $user;
    }

    private function payload(float $amount = 1150): array
    {
        return [
            'vendor_id' => $this->vendorId,
            'payment_date' => '2026-03-20',
            'bank_account_id' => $this->bankId,
            'payment_method' => 'bank_transfer',
            'reference' => 'TT-55',
            'allocations' => [['vendor_invoice_id' => $this->invoiceId, 'amount' => $amount]],
        ];
    }

    public function test_payable_invoices_lists_posted_unpaid(): void
    {
        $this->actingAsRole('accountant');

        $this->getJson("/api/v1/accounts-payable/payable-invoices?vendor_id={$this->vendorId}")
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.balance_due', '1150.00');
    }

    public function test_create_payment_sums_allocations(): void
    {
        $this->actingAsRole('accountant');

        $this->postJson('/api/v1/accounts-payable/vendor-payments', $this->payload())
            ->assertCreated()
            ->assertJsonPath('data.amount', '1150.00')
            ->assertJsonPath('data.status', 'draft');
    }

    public function test_cannot_allocate_more_than_balance(): void
    {
        $this->actingAsRole('accountant');

        $this->postJson('/api/v1/accounts-payable/vendor-payments', $this->payload(2000))
            ->assertStatus(422);
    }

    public function test_approve_then_post_dr_ap_cr_bank_and_settles_invoice(): void
    {
        $this->actingAsRole('accountant');
        $payment = $this->postJson('/api/v1/accounts-payable/vendor-payments', $this->payload())->json('data');

        $this->postJson("/api/v1/accounts-payable/vendor-payments/{$payment['id']}/submit")
            ->assertOk()->assertJsonPath('data.status', 'pending_approval');

        $requestId = ApprovalRequest::query()->where('approvable_type', VendorPayment::class)->where('approvable_id', $payment['id'])->value('id');

        $this->actingAsRole('finance-controller');
        $this->postJson("/api/v1/approvals/{$requestId}/approve")->assertOk();

        $this->actingAsRole('finance-manager');
        $this->postJson("/api/v1/accounts-payable/vendor-payments/{$payment['id']}/post")
            ->assertOk()->assertJsonPath('data.status', 'posted');

        $rows = GlTransaction::query()->where('source_type', VendorPayment::class)->where('source_id', $payment['id'])->get();
        $this->assertCount(2, $rows);
        $this->assertEqualsWithDelta(1150, $rows->sum('base_debit'), 0.01);
        $this->assertEqualsWithDelta(1150, $rows->sum('base_credit'), 0.01);

        // The invoice is now fully settled.
        $invoice = VendorInvoice::query()->find($this->invoiceId);
        $this->assertEqualsWithDelta(1150, (float) $invoice->amount_paid, 0.01);
        $this->assertEqualsWithDelta(0, $invoice->balanceDue(), 0.01);
    }

    public function test_cannot_post_an_unapproved_payment(): void
    {
        $this->actingAsRole('accountant');
        $payment = $this->postJson('/api/v1/accounts-payable/vendor-payments', $this->payload())->json('data');

        $this->actingAsRole('finance-manager');
        $this->postJson("/api/v1/accounts-payable/vendor-payments/{$payment['id']}/post")
            ->assertStatus(409);
    }
}
