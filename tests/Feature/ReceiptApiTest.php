<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\ApprovalRequest;
use App\Models\ChartOfAccount;
use App\Models\Currency;
use App\Models\Customer;
use App\Models\CustomerInvoice;
use App\Models\FiscalPeriod;
use App\Models\FiscalYear;
use App\Models\GlTransaction;
use App\Models\Receipt;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\ApprovalWorkflowSeeder;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * P4.11–P4.14 — Customer receipt: allocate to posted invoices, approve, then
 * post (Dr bank → Cr AR) and settle the invoice.
 */
final class ReceiptApiTest extends TestCase
{
    use RefreshDatabase;

    private int $customerId;
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

        $receivable = ChartOfAccount::query()->create(['code' => '1205', 'name' => 'Accounts receivable', 'account_type' => 'asset', 'normal_balance' => 'debit', 'level' => 1, 'is_postable' => true, 'status' => 'active']);
        $bank = ChartOfAccount::query()->create(['code' => '110101', 'name' => 'Bank — current', 'account_type' => 'asset', 'normal_balance' => 'debit', 'level' => 1, 'is_postable' => true, 'is_bank_account' => true, 'status' => 'active']);

        $year = FiscalYear::query()->create(['code' => 'FY2026', 'name' => 'FY2026', 'start_date' => '2026-01-01', 'end_date' => '2026-12-31', 'status' => 'open']);
        FiscalPeriod::query()->create(['fiscal_year_id' => $year->id, 'period_number' => 3, 'name' => 'Mar 2026', 'start_date' => '2026-03-01', 'end_date' => '2026-03-31', 'status' => 'open']);

        $creator = User::factory()->create();
        $customer = Customer::query()->create([
            'customer_code' => 'CUST-2026-000001', 'name' => 'Gulf Logistics', 'customer_type' => 'corporate',
            'status' => 'active', 'currency_code' => 'SAR', 'default_receivable_account_id' => $receivable->id,
            'payment_terms_days' => 30, 'created_by' => $creator->id,
        ]);

        $invoice = CustomerInvoice::query()->create([
            'invoice_number' => 'SINV-2026-000001', 'customer_id' => $customer->id,
            'invoice_date' => '2026-03-10', 'currency_code' => 'SAR', 'exchange_rate' => 1,
            'subtotal' => 1000, 'tax_amount' => 150, 'total_amount' => 1150, 'amount_paid' => 0,
            'status' => 'posted', 'created_by' => $creator->id,
        ]);

        $this->customerId = $customer->id;
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
            'customer_id' => $this->customerId,
            'receipt_date' => '2026-03-20',
            'bank_account_id' => $this->bankId,
            'payment_method' => 'bank_transfer',
            'reference' => 'TT-77',
            'allocations' => [['customer_invoice_id' => $this->invoiceId, 'amount' => $amount]],
        ];
    }

    public function test_receivable_invoices_lists_posted_unpaid(): void
    {
        $this->actingAsRole('accountant');

        $this->getJson("/api/v1/accounts-receivable/receivable-invoices?customer_id={$this->customerId}")
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.balance_due', '1150.00');
    }

    public function test_create_receipt_sums_allocations(): void
    {
        $this->actingAsRole('accountant');

        $this->postJson('/api/v1/accounts-receivable/receipts', $this->payload())
            ->assertCreated()
            ->assertJsonPath('data.amount', '1150.00')
            ->assertJsonPath('data.status', 'draft');
    }

    public function test_cannot_allocate_more_than_balance(): void
    {
        $this->actingAsRole('accountant');

        $this->postJson('/api/v1/accounts-receivable/receipts', $this->payload(2000))
            ->assertStatus(422);
    }

    public function test_approve_then_post_dr_bank_cr_ar_and_settles_invoice(): void
    {
        $this->actingAsRole('accountant');
        $receipt = $this->postJson('/api/v1/accounts-receivable/receipts', $this->payload())->json('data');

        $this->postJson("/api/v1/accounts-receivable/receipts/{$receipt['id']}/submit")
            ->assertOk()->assertJsonPath('data.status', 'pending_approval');

        $requestId = ApprovalRequest::query()->where('approvable_type', Receipt::class)->where('approvable_id', $receipt['id'])->value('id');

        $this->actingAsRole('finance-controller');
        $this->postJson("/api/v1/approvals/{$requestId}/approve")->assertOk();

        $this->actingAsRole('finance-manager');
        $this->postJson("/api/v1/accounts-receivable/receipts/{$receipt['id']}/post")
            ->assertOk()->assertJsonPath('data.status', 'posted');

        $rows = GlTransaction::query()->where('source_type', Receipt::class)->where('source_id', $receipt['id'])->get();
        $this->assertCount(2, $rows);
        $this->assertEqualsWithDelta(1150, $rows->sum('base_debit'), 0.01);
        $this->assertEqualsWithDelta(1150, $rows->sum('base_credit'), 0.01);
        // Bank leg is the debit (cash in).
        $this->assertEqualsWithDelta(1150, $rows->where('base_debit', '>', 0)->sum('base_debit'), 0.01);

        // The invoice is now fully settled.
        $invoice = CustomerInvoice::query()->find($this->invoiceId);
        $this->assertEqualsWithDelta(1150, (float) $invoice->amount_paid, 0.01);
        $this->assertEqualsWithDelta(0, $invoice->balanceDue(), 0.01);
    }

    public function test_cannot_post_an_unapproved_receipt(): void
    {
        $this->actingAsRole('accountant');
        $receipt = $this->postJson('/api/v1/accounts-receivable/receipts', $this->payload())->json('data');

        $this->actingAsRole('finance-manager');
        $this->postJson("/api/v1/accounts-receivable/receipts/{$receipt['id']}/post")
            ->assertStatus(409);
    }

    public function test_update_draft_receipt_changes_allocations(): void
    {
        $this->actingAsRole('accountant');
        $receipt = $this->postJson('/api/v1/accounts-receivable/receipts', $this->payload(1150))->assertCreated()->json('data');

        $updated = $this->putJson("/api/v1/accounts-receivable/receipts/{$receipt['id']}", $this->payload(500))
            ->assertOk()->json('data');

        $this->assertSame('500.00', $updated['amount']);
    }
}
