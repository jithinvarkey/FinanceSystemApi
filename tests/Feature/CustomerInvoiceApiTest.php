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
use App\Models\Role;
use App\Models\TaxCode;
use App\Models\User;
use Database\Seeders\ApprovalWorkflowSeeder;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * P4.6–P4.10 — Customer invoice: VAT 15%, credit-limit check, and the
 * approve → post flow that writes Dr AR → Cr revenue + Cr output VAT.
 */
final class CustomerInvoiceApiTest extends TestCase
{
    use RefreshDatabase;

    private int $revenueId;
    private int $taxCodeId;
    private int $customerId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed([RbacSeeder::class, ApprovalWorkflowSeeder::class]);
        $this->buildFixture();
    }

    private function buildFixture(int $creditLimit = 0): void
    {
        Currency::query()->firstOrCreate(['code' => 'SAR'], ['name' => 'Saudi Riyal', 'symbol' => 'SR', 'decimal_places' => 2, 'is_base' => true, 'status' => 'active']);

        $revenue = ChartOfAccount::query()->create(['code' => '4100', 'name' => 'Brokerage income', 'account_type' => 'revenue', 'normal_balance' => 'credit', 'level' => 1, 'is_postable' => true, 'status' => 'active']);
        $receivable = ChartOfAccount::query()->create(['code' => '1205', 'name' => 'Accounts receivable', 'account_type' => 'asset', 'normal_balance' => 'debit', 'level' => 1, 'is_postable' => true, 'status' => 'active']);
        // Output VAT as a header with a postable leaf, to exercise resolvePostable.
        $vatHeader = ChartOfAccount::query()->create(['code' => '223', 'name' => 'VAT Payable', 'account_type' => 'liability', 'normal_balance' => 'credit', 'level' => 1, 'is_postable' => false, 'status' => 'active']);
        ChartOfAccount::query()->create(['code' => '2230', 'name' => 'VAT Payable', 'account_type' => 'liability', 'normal_balance' => 'credit', 'level' => 2, 'parent_id' => $vatHeader->id, 'is_postable' => true, 'status' => 'active']);

        $vat = TaxCode::query()->create(['code' => 'VAT15', 'name' => 'Saudi VAT 15%', 'tax_type' => 'both', 'rate' => 15.0, 'output_account_id' => $vatHeader->id, 'is_recoverable' => true, 'status' => 'active']);

        $year = FiscalYear::query()->create(['code' => 'FY2026', 'name' => 'FY2026', 'start_date' => '2026-01-01', 'end_date' => '2026-12-31', 'status' => 'open']);
        FiscalPeriod::query()->create(['fiscal_year_id' => $year->id, 'period_number' => 3, 'name' => 'Mar 2026', 'start_date' => '2026-03-01', 'end_date' => '2026-03-31', 'status' => 'open']);

        $creator = User::factory()->create();
        $customer = Customer::query()->create([
            'customer_code' => 'CUST-2026-000001', 'name' => 'Gulf Logistics', 'customer_type' => 'corporate',
            'status' => 'active', 'currency_code' => 'SAR', 'default_receivable_account_id' => $receivable->id,
            'payment_terms_days' => 30, 'credit_limit' => $creditLimit, 'created_by' => $creator->id,
        ]);

        $this->revenueId = $revenue->id;
        $this->taxCodeId = $vat->id;
        $this->customerId = $customer->id;
    }

    private function actingAsRole(string $role): User
    {
        $user = User::factory()->create();
        $user->roles()->attach(Role::query()->where('name', $role)->firstOrFail());
        Sanctum::actingAs($user->fresh());

        return $user;
    }

    private function payload(array $overrides = []): array
    {
        return array_merge([
            'customer_id' => $this->customerId,
            'invoice_date' => '2026-03-15',
            'description' => 'March brokerage',
            'lines' => [
                ['account_id' => $this->revenueId, 'amount' => 1000, 'tax_code_id' => $this->taxCodeId, 'description' => 'Commission'],
            ],
        ], $overrides);
    }

    public function test_create_computes_vat_15_percent(): void
    {
        $this->actingAsRole('accountant');

        $this->postJson('/api/v1/accounts-receivable/customer-invoices', $this->payload())
            ->assertCreated()
            ->assertJsonPath('data.subtotal', '1000.00')
            ->assertJsonPath('data.tax_amount', '150.00')
            ->assertJsonPath('data.total_amount', '1150.00')
            ->assertJsonPath('data.status', 'draft');
    }

    public function test_approve_then_post_writes_dr_ar_cr_revenue_and_output_vat(): void
    {
        $this->actingAsRole('accountant');
        $invoice = $this->postJson('/api/v1/accounts-receivable/customer-invoices', $this->payload())->json('data');

        $this->postJson("/api/v1/accounts-receivable/customer-invoices/{$invoice['id']}/submit")
            ->assertOk()->assertJsonPath('data.status', 'pending_approval');

        $requestId = ApprovalRequest::query()->where('approvable_type', CustomerInvoice::class)->where('approvable_id', $invoice['id'])->value('id');

        $this->actingAsRole('finance-controller');
        $this->postJson("/api/v1/approvals/{$requestId}/approve")->assertOk();

        $this->actingAsRole('finance-manager');
        $this->postJson("/api/v1/accounts-receivable/customer-invoices/{$invoice['id']}/post")
            ->assertOk()->assertJsonPath('data.status', 'posted');

        $rows = GlTransaction::query()->where('source_type', CustomerInvoice::class)->where('source_id', $invoice['id'])->get();
        $this->assertCount(3, $rows); // receivable + revenue + output VAT
        $this->assertEqualsWithDelta(1150, $rows->sum('base_debit'), 0.01);
        $this->assertEqualsWithDelta(1150, $rows->sum('base_credit'), 0.01);
        // AR leg = 1150 debit; output VAT = 150 credit to the postable VAT leaf.
        $this->assertEqualsWithDelta(1150, $rows->where('base_debit', '>', 0)->sum('base_debit'), 0.01);
        $this->assertEqualsWithDelta(150, $rows->firstWhere('description', 'Output VAT')->base_credit, 0.01);
    }

    public function test_submit_blocked_when_over_credit_limit(): void
    {
        // Tighten the credit limit to 500; a 1,150 invoice should be blocked at submit.
        Customer::query()->whereKey($this->customerId)->update(['credit_limit' => 500]);

        $this->actingAsRole('accountant');
        $invoice = $this->postJson('/api/v1/accounts-receivable/customer-invoices', $this->payload())->json('data');

        $this->postJson("/api/v1/accounts-receivable/customer-invoices/{$invoice['id']}/submit")
            ->assertStatus(409);

        $this->assertSame('draft', CustomerInvoice::query()->find($invoice['id'])->status->value);
    }

    public function test_cannot_post_an_unapproved_invoice(): void
    {
        $this->actingAsRole('accountant');
        $invoice = $this->postJson('/api/v1/accounts-receivable/customer-invoices', $this->payload())->json('data');

        $this->actingAsRole('finance-manager');
        $this->postJson("/api/v1/accounts-receivable/customer-invoices/{$invoice['id']}/post")
            ->assertStatus(409);
    }
}
